<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use ArcadeCloud\Drive\Activity\TranscriptionCostAttribution;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\TranscribeService\TranscribeServiceClient;
use RuntimeException;

final class TranscriptionFileService
{
    private const FORMATS = ['mp3', 'mp4', 'wav', 'flac', 'ogg', 'amr', 'webm', 'm4a', 'opus'];

    public function __construct(
        private FileRecordLocator $locator,
        private GeneratedFileRepository $generated,
        private S3Client $s3,
        private TranscribeServiceClient $transcribe,
        private string $bucket
    ) {
    }

    public function start(int $userId, array $input): array
    {
        $requested = trim((string)($input['archivo'] ?? ''));
        $row = $this->locator->requireReadableByKey($userId, $requested);
        $key = (string)$row['_key'];
        $visible = (string)$row['Nombre'];
        $route = $this->route((string)$row['Ruta']);
        $ext = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));

        if (!in_array($ext, self::FORMATS, true)) {
            throw new RuntimeException('Formato no soportado para transcripción');
        }

        $mediaFormat = $ext === 'opus' ? 'ogg' : $ext;
        $baseVisible = pathinfo($visible, PATHINFO_FILENAME);
        $jobName = $this->jobName((string)($input['jobName'] ?? $baseVisible));
        $languageMode = trim((string)($input['languageMode'] ?? 'specific')) ?: 'specific';
        $languageCode = trim((string)($input['languageCode'] ?? 'es-ES')) ?: 'es-ES';
        $languageOptions = $this->arrayValue($input['languageOptions'] ?? []);
        $subtitleFormats = array_values(array_intersect(
            $this->arrayValue($input['subtitleFormats'] ?? []),
            ['vtt', 'srt']
        ));

        $channel = $this->boolValue($input['enableChannelIdentification'] ?? false);
        $speaker = $this->boolValue($input['enableShowSpeakerLabels'] ?? false);
        if ($channel && $speaker) {
            throw new RuntimeException('No puedes usar identificación de canales y partición de voces al mismo tiempo.');
        }

        $redaction = $this->boolValue($input['enableContentRedaction'] ?? false);
        if (
            $redaction
            && (!in_array($languageCode, ['en-US', 'es-US'], true) || $languageMode !== 'specific')
        ) {
            throw new RuntimeException('La redacción PII requiere idioma específico en-US o es-US.');
        }

        if ($this->boolValue($input['enablePhi'] ?? false)) {
            throw new RuntimeException('PHI requiere Amazon Transcribe Medical y no está disponible en este flujo estándar.');
        }

        $params = [
            'TranscriptionJobName' => $jobName,
            'Media' => ['MediaFileUri' => 's3://' . $this->bucket . '/' . $key],
            'MediaFormat' => $mediaFormat,
            'OutputBucketName' => $this->bucket,
        ];

        if ($route !== '') {
            $params['OutputKey'] = $route;
        }

        if ($languageMode === 'auto') {
            $params['IdentifyLanguage'] = true;
            if ($languageOptions) {
                $params['LanguageOptions'] = $languageOptions;
            }
        } elseif ($languageMode === 'auto_multi') {
            $params['IdentifyMultipleLanguages'] = true;
            if ($languageOptions) {
                $params['LanguageOptions'] = $languageOptions;
            }
        } else {
            $params['LanguageCode'] = $languageCode;
        }

        $settings = [];
        if ($channel) {
            $settings['ChannelIdentification'] = true;
        }
        if ($speaker) {
            $settings['ShowSpeakerLabels'] = true;
            $settings['MaxSpeakerLabels'] = $this->clamp((int)($input['maxSpeakerLabels'] ?? 10), 2, 30);
        }
        if ($this->boolValue($input['showAlternatives'] ?? false)) {
            $settings['ShowAlternatives'] = true;
            $settings['MaxAlternatives'] = $this->clamp((int)($input['maxAlternatives'] ?? 2), 2, 10);
        }

        $vocab = trim((string)($input['vocabularyName'] ?? ''));
        if ($vocab !== '') {
            $settings['VocabularyName'] = $vocab;
        }

        $filter = trim((string)($input['vocabularyFilterName'] ?? ''));
        if ($filter !== '') {
            $settings['VocabularyFilterName'] = $filter;
            $method = (string)($input['vocabularyFilterMethod'] ?? 'remove');
            $settings['VocabularyFilterMethod'] = in_array($method, ['remove', 'mask', 'tag'], true)
                ? $method
                : 'remove';
        }

        if ($settings) {
            $params['Settings'] = $settings;
        }

        if (
            (string)($input['modelType'] ?? 'general') === 'custom'
            && trim((string)($input['customLanguageModelName'] ?? '')) !== ''
        ) {
            $params['ModelSettings'] = [
                'LanguageModelName' => trim((string)$input['customLanguageModelName']),
            ];
        }

        if ($subtitleFormats) {
            $params['Subtitles'] = [
                'Formats' => $subtitleFormats,
                'OutputStartIndex' => 1,
            ];
        }

        if ($redaction) {
            $params['ContentRedaction'] = [
                'RedactionType' => 'PII',
                'RedactionOutput' => 'redacted_and_unredacted',
            ];
            $pii = $this->arrayValue($input['piiEntityTypes'] ?? []);
            if ($pii) {
                $params['ContentRedaction']['PiiEntityTypes'] = $pii;
            }
        }

        if ($this->boolValue($input['toxicityDetection'] ?? false)) {
            $tox = $this->arrayValue($input['toxicityCategories'] ?? []);
            $item = [];
            if ($tox) {
                $item['ToxicityCategories'] = $tox;
            }
            $params['ToxicityDetection'] = [$item];
        }

        if ($this->jobExists($jobName)) {
            $jobName .= '_' . date('YmdHis');
            $params['TranscriptionJobName'] = $jobName;
        }

        $result = $this->transcribe->startTranscriptionJob($params);
        $job = $result['TranscriptionJob'] ?? [];

        $physicalBase = pathinfo(basename($key), PATHINFO_FILENAME);

        return [
            'ok' => true,
            'jobName' => $jobName,
            'status' => $job['TranscriptionJobStatus'] ?? null,
            'inputS3Uri' => 's3://' . $this->bucket . '/' . $key,
            'archivo' => $requested,
            'archivoVisible' => $visible,
            'archivoEncriptado' => $key,
            'rutaDestino' => $route,
            'nombreBase' => $baseVisible,
            'subtitleFormats' => $subtitleFormats,
            'awsOutputJsonKey' => $route . $jobName . '.json',
            'driveOutputJsonKey' => $route . $physicalBase . '.json',
            'driveOutputName' => $baseVisible . '.json',
        ];
    }

    public function status(int $userId, string $jobName, string $requestedKey = ''): array
    {
        $jobName = trim($jobName);
        if ($jobName === '') {
            throw new RuntimeException('Falta jobName.');
        }

        $result = $this->transcribe->getTranscriptionJob([
            'TranscriptionJobName' => $jobName,
        ]);
        $job = $result['TranscriptionJob'] ?? [];
        if (!$job) {
            throw new RuntimeException('Job no encontrado.');
        }

        $mediaKey = $this->s3KeyFromUri((string)($job['Media']['MediaFileUri'] ?? ''));
        $source = $this->locator->requireReadableByKey(
            $userId,
            $mediaKey !== '' ? $mediaKey : $requestedKey
        );
        $status = (string)($job['TranscriptionJobStatus'] ?? 'UNKNOWN');

        $response = [
            'ok' => true,
            'jobName' => $jobName,
            'status' => $status,
            'message' => $job['FailureReason'] ?? null,
            'languageCode' => $job['LanguageCode'] ?? null,
            'identifiedLanguageScore' => $job['IdentifiedLanguageScore'] ?? null,
            'transcriptUri' => $job['Transcript']['TranscriptFileUri'] ?? null,
            'redactedTranscriptUri' => $job['Transcript']['RedactedTranscriptFileUri'] ?? null,
            'subtitleUris' => $job['Subtitles']['SubtitleFileUris'] ?? [],
            'archivoKey' => $source['_key'],
            'archivoNombre' => $source['Nombre'],
            'ruta' => $source['Ruta'],
        ];

        if ($status !== 'COMPLETED') {
            return $response;
        }

        $transcriptUri = (string)($job['Transcript']['TranscriptFileUri'] ?? '');
        if ($transcriptUri === '') {
            throw new RuntimeException('Amazon no devolvió TranscriptFileUri.');
        }

        $jsonBody = $this->fetchResult($transcriptUri);
        $decoded = json_decode($jsonBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('El resultado no es un JSON válido de Amazon Transcribe.');
        }

        $text = (string)($decoded['results']['transcripts'][0]['transcript'] ?? '');
        $costAttribution = TranscriptionCostAttribution::fromResult($decoded, $job);
        $costMetadata = [
            'duration_seconds_observed' => $costAttribution['duration_seconds_observed'],
            'billable_seconds_reference' => $costAttribution['billable_seconds_reference'],
            'duration_source' => $costAttribution['duration_source'],
            'pricing_region_reference' => $costAttribution['pricing_region_reference'],
        ];

        $saved = [];
        $saved['json'] = $this->storeVariant(
            $userId,
            $source,
            $jobName,
            (string)($job['LanguageCode'] ?? ''),
            $status,
            $jsonBody,
            'json',
            'application/json',
            array_merge(['transcript_uri' => $transcriptUri], $costMetadata)
        );

        foreach ((array)($job['Subtitles']['SubtitleFileUris'] ?? []) as $uri) {
            $ext = strtolower((string)pathinfo(
                (string)parse_url((string)$uri, PHP_URL_PATH),
                PATHINFO_EXTENSION
            ));
            if (!in_array($ext, ['srt', 'vtt'], true)) {
                continue;
            }

            $saved[$ext] = $this->storeVariant(
                $userId,
                $source,
                $jobName,
                (string)($job['LanguageCode'] ?? ''),
                $status,
                $this->fetchResult((string)$uri),
                $ext,
                $ext === 'srt' ? 'application/x-subrip' : 'text/vtt',
                array_merge([
                    'subtitle_uri' => $uri,
                    'subtitle_format' => $ext,
                ], $costMetadata)
            );
        }

        $response['texto'] = $text;
        $response['json_s3_key'] = $saved['json']['key'];
        $response['json_nombre'] = $saved['json']['nombre'];
        $response['guardados'] = $saved;
        $response['cost_attribution'] = $costAttribution;

        return $response;
    }

    public function recoverCompletedFromS3(
        int $userId,
        string $jobName,
        string $requestedKey,
        string $expectedAwsJsonKey = '',
        array $subtitleFormats = []
    ): ?array {
        $source = $this->locator->requireReadableByKey($userId, $requestedKey);
        $sourceKey = (string)$source['_key'];
        $route = $this->route((string)$source['Ruta']);
        $physical = pathinfo(basename($sourceKey), PATHINFO_FILENAME);
        $visible = pathinfo((string)$source['Nombre'], PATHINFO_FILENAME);
        $canonicalJsonKey = $route . $physical . '.json';

        $candidateKeys = array_values(array_unique(array_filter([
            $canonicalJsonKey,
            trim($expectedAwsJsonKey),
            $jobName !== '' ? $route . $jobName . '.json' : '',
        ], static fn(string $value): bool => $value !== '')));

        $jsonObject = null;
        $jsonKey = '';
        foreach ($candidateKeys as $candidate) {
            $object = $this->readS3ObjectIfExists($candidate);
            if ($object !== null) {
                $jsonObject = $object;
                $jsonKey = $candidate;
                break;
            }
        }

        if ($jsonObject === null) {
            return null;
        }

        $jsonBody = (string)$jsonObject['body'];
        $decoded = json_decode($jsonBody, true);
        if (!is_array($decoded) || !is_array($decoded['results'] ?? null)) {
            return null;
        }

        $costAttribution = TranscriptionCostAttribution::fromResult($decoded, []);
        $costMetadata = [
            'duration_seconds_observed' => $costAttribution['duration_seconds_observed'],
            'billable_seconds_reference' => $costAttribution['billable_seconds_reference'],
            'duration_source' => $costAttribution['duration_source'],
            'pricing_region_reference' => $costAttribution['pricing_region_reference'],
            'recovered_from_s3' => true,
        ];

        if (hash_equals($canonicalJsonKey, $jsonKey)) {
            $savedJson = $this->registerExistingVariant(
                $userId,
                $source,
                $jobName,
                $canonicalJsonKey,
                $visible . '.json',
                (int)$jsonObject['size'],
                'json',
                'application/json',
                $costMetadata
            );
        } else {
            $savedJson = $this->storeVariant(
                $userId,
                $source,
                $jobName,
                '',
                'COMPLETED',
                $jsonBody,
                'json',
                'application/json',
                array_merge(['recovered_aws_key' => $jsonKey], $costMetadata)
            );
        }

        $saved = ['json' => $savedJson];
        foreach (array_values(array_intersect($subtitleFormats, ['srt', 'vtt'])) as $ext) {
            $canonicalKey = $route . $physical . '.' . $ext;
            $rawKey = $jobName !== '' ? $route . $jobName . '.' . $ext : '';
            $object = $this->readS3ObjectIfExists($canonicalKey);
            $foundKey = $canonicalKey;

            if ($object === null && $rawKey !== '') {
                $object = $this->readS3ObjectIfExists($rawKey);
                $foundKey = $rawKey;
            }
            if ($object === null) continue;

            $contentType = $ext === 'srt' ? 'application/x-subrip' : 'text/vtt';
            if (hash_equals($canonicalKey, $foundKey)) {
                $saved[$ext] = $this->registerExistingVariant(
                    $userId,
                    $source,
                    $jobName,
                    $canonicalKey,
                    $visible . '.' . $ext,
                    (int)$object['size'],
                    $ext,
                    $contentType,
                    $costMetadata
                );
            } else {
                $saved[$ext] = $this->storeVariant(
                    $userId,
                    $source,
                    $jobName,
                    '',
                    'COMPLETED',
                    (string)$object['body'],
                    $ext,
                    $contentType,
                    array_merge(['recovered_aws_key' => $foundKey], $costMetadata)
                );
            }
        }

        return [
            'ok' => true,
            'jobName' => $jobName,
            'status' => 'COMPLETED',
            'texto' => (string)($decoded['results']['transcripts'][0]['transcript'] ?? ''),
            'archivoKey' => $sourceKey,
            'archivoNombre' => (string)$source['Nombre'],
            'ruta' => (string)$source['Ruta'],
            'json_s3_key' => (string)$savedJson['key'],
            'json_nombre' => (string)$savedJson['nombre'],
            'guardados' => $saved,
            'cost_attribution' => $costAttribution,
            'recovered_from_s3' => true,
        ];
    }

    private function registerExistingVariant(
        int $userId,
        array $source,
        string $job,
        string $key,
        string $name,
        int $size,
        string $ext,
        string $contentType,
        array $extra
    ): array {
        $route = $this->route((string)$source['Ruta']);
        $meta = array_merge([
            'tipo' => $contentType,
            'servicio' => 'Amazon Transcribe',
            'jobName' => $job,
            'origen_nombre' => $source['Nombre'],
            'origen_encriptado' => $source['_key'],
            'destino_nombre' => $name,
            'destino_encriptado' => $key,
            'ruta' => $route,
            'tamano_bytes' => $size,
            'status' => 'COMPLETED',
            'recovered_existing_s3_object' => true,
            'extension' => $ext,
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
        ], $extra);

        $db = $this->generated->upsert($userId, $name, $key, $size, $meta, $route);

        return [
            'nombre' => $name,
            'key' => $key,
            'ruta' => $route,
            'tamano' => $size,
            'db_status' => $db,
            's3_put' => false,
        ];
    }

    private function readS3ObjectIfExists(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') return null;

        try {
            $head = $this->s3->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            $obj = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            $body = (string)$obj['Body'];
            if ($body === '') return null;

            return [
                'body' => $body,
                'size' => max(0, (int)($head['ContentLength'] ?? strlen($body))),
            ];
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 404
                || in_array((string)$e->getAwsErrorCode(), ['NotFound','NoSuchKey'], true)) {
                return null;
            }
            throw $e;
        }
    }

    private function storeVariant(
        int $userId,
        array $source,
        string $job,
        string $language,
        string $status,
        string $body,
        string $ext,
        string $contentType,
        array $extra
    ): array {
        if ($body === '') {
            throw new RuntimeException('El archivo generado está vacío: ' . $ext);
        }

        $sourceKey = (string)$source['_key'];
        $route = $this->route((string)$source['Ruta']);
        $physical = pathinfo(basename($sourceKey), PATHINFO_FILENAME);
        $visible = pathinfo((string)$source['Nombre'], PATHINFO_FILENAME);
        $key = $route . $physical . '.' . $ext;
        $name = $visible . '.' . $ext;
        $size = strlen($body);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ACL' => 'private',
            'ContentType' => $contentType,
            'Metadata' => [
                'origin' => $sourceKey,
                'jobname' => $job,
                'service' => 'amazon-transcribe',
            ],
        ]);

        $meta = array_merge([
            'tipo' => $contentType,
            'servicio' => 'Amazon Transcribe',
            'jobName' => $job,
            'languageCode' => $language,
            'origen_nombre' => $source['Nombre'],
            'origen_encriptado' => $sourceKey,
            'destino_nombre' => $name,
            'destino_encriptado' => $key,
            'ruta' => $route,
            'tamano_bytes' => $size,
            'status' => $status,
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
        ], $extra);

        $db = $this->generated->upsert($userId, $name, $key, $size, $meta, $route);

        return [
            'nombre' => $name,
            'key' => $key,
            'ruta' => $route,
            'tamano' => $size,
            'db_status' => $db,
            's3_put' => true,
        ];
    }

    private function fetchResult(string $uri): string
    {
        [$bucket, $key] = $this->s3Location($uri);
        if ($bucket !== null && $key !== null) {
            try {
                $obj = $this->s3->getObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]);
                return (string)$obj['Body'];
            } catch (\Throwable) {
            }
        }

        $context = stream_context_create([
            'http' => ['timeout' => 60, 'ignore_errors' => true],
            'https' => ['timeout' => 60, 'ignore_errors' => true],
        ]);
        $data = @file_get_contents($uri, false, $context);
        if ($data === false || $data === '') {
            throw new RuntimeException('No se pudo descargar el resultado de transcripción.');
        }
        if (stripos(ltrim($data), '<Code>AccessDenied</Code>') !== false) {
            throw new RuntimeException('Amazon devolvió AccessDenied al leer el resultado de transcripción.');
        }
        return $data;
    }

    private function s3Location(string $uri): array
    {
        if (str_starts_with($uri, 's3://')) {
            $rest = substr($uri, 5);
            $pos = strpos($rest, '/');
            return $pos === false
                ? [null, null]
                : [substr($rest, 0, $pos), ltrim(substr($rest, $pos + 1), '/')];
        }

        $parts = parse_url($uri);
        if (!is_array($parts)) {
            return [null, null];
        }

        $host = (string)($parts['host'] ?? '');
        $path = ltrim((string)($parts['path'] ?? ''), '/');

        if (preg_match('/^(.+)\.s3(?:[.-][^.]+)?\.amazonaws\.com$/i', $host, $m)) {
            return [$m[1], $path];
        }
        if (preg_match('/^s3(?:[.-][^.]+)?\.amazonaws\.com$/i', $host)) {
            $pos = strpos($path, '/');
            return $pos === false
                ? [null, null]
                : [substr($path, 0, $pos), substr($path, $pos + 1)];
        }

        return [null, null];
    }

    private function s3KeyFromUri(string $uri): string
    {
        [, $key] = $this->s3Location($uri);
        return $key ?? '';
    }

    private function route(string $route): string
    {
        $route = ltrim(
            (string)(preg_replace('~/+~', '/', str_replace('\\', '/', $route)) ?? $route),
            '/'
        );
        return $route !== '' ? rtrim($route, '/') . '/' : '';
    }

    private function jobName(string $name): string
    {
        $name = (string)preg_replace('/\.[^.]+$/', '', $name);
        $name = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $name = trim($name, '.-_') ?: 'transcripcion';
        return substr($name, 0, 200);
    }

    private function jobExists(string $name): bool
    {
        try {
            $this->transcribe->getTranscriptionJob([
                'TranscriptionJobName' => $name,
            ]);
            return true;
        } catch (\Aws\Exception\AwsException) {
            return false;
        }
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(
            strtolower(trim((string)$value)),
            ['1', 'true', 'yes', 'on', 'si', 'sí'],
            true
        );
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            $array = $value;
        } else {
            $string = trim((string)$value);
            $array = $string === '' ? [] : explode(',', $string);
        }

        return array_values(array_filter(
            array_map(static fn($item): string => trim((string)$item), $array),
            static fn(string $item): bool => $item !== ''
        ));
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
