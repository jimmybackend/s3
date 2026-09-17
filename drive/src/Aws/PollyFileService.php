<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Exception\AwsException;
use Aws\Polly\PollyClient;
use Aws\S3\S3Client;
use RuntimeException;

final class PollyFileService
{
    private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'jas'];
    private const SYNC_BILLED_LIMIT = 3000;
    private const ASYNC_BILLED_LIMIT = 100000;

    public function __construct(
        private FileRecordLocator $locator,
        private GeneratedFileRepository $generated,
        private S3Client $s3,
        private PollyClient $polly,
        private string $bucket
    ) {
    }

    public function voices(int $userId, string $language = ''): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Sesión inválida.');
        }

        $voices = [];
        $args = $language !== '' ? ['LanguageCode' => $language] : [];

        do {
            $resp = $this->polly->describeVoices($args);
            foreach ((array)($resp['Voices'] ?? []) as $voice) {
                $voices[] = [
                    'Id' => $voice['Id'],
                    'Name' => $voice['Name'] ?? $voice['Id'],
                    'LanguageCode' => $voice['LanguageCode'] ?? null,
                    'Gender' => $voice['Gender'] ?? null,
                    'SupportedEngines' => $voice['SupportedEngines'] ?? [],
                ];
            }

            $nextToken = trim((string)($resp['NextToken'] ?? ''));
            if ($nextToken !== '') {
                $args['NextToken'] = $nextToken;
            } else {
                unset($args['NextToken']);
            }
        } while ($nextToken !== '');

        usort($voices, static fn(array $a, array $b): int => strcmp((string)$a['Name'], (string)$b['Name']));

        return ['ok' => true, 'voices' => $voices];
    }

    public function loadText(int $userId, string $key): array
    {
        $row = $this->locator->requireReadableByKey($userId, $key);
        $real = (string)$row['_key'];
        $ext = strtolower((string)pathinfo((string)($row['Nombre'] ?? $real), PATHINFO_EXTENSION));

        if (!in_array($ext, self::TEXT_EXTENSIONS, true)) {
            throw new RuntimeException('Solo se pueden cargar archivos de texto compatibles en Polly.');
        }

        $obj = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $real,
        ]);
        $body = (string)$obj['Body'];

        if (function_exists('mb_detect_encoding') && !mb_detect_encoding($body, 'UTF-8', true)) {
            $body = mb_convert_encoding($body, 'UTF-8');
        }

        return [
            'ok' => true,
            'archivo' => $real,
            'file_id' => (int)$row['id_'],
            'texto' => $body,
            'bytes' => strlen($body),
        ];
    }

    public function synthesize(int $userId, array $input): array
    {
        $text = trim((string)($input['texto'] ?? ''));
        $voice = trim((string)($input['voiceId'] ?? ''));
        $engine = strtolower(trim((string)($input['engine'] ?? 'neural')));
        $requestedFormat = strtolower(trim((string)($input['format'] ?? 'mp3')));
        $sample = trim((string)($input['sampleRate'] ?? '22050'));
        $from = trim((string)($input['from_key'] ?? $input['fromKey'] ?? $input['pollyArchivoKey'] ?? ''));
        $toS3 = (int)($input['to_s3'] ?? 1);

        if ($text === '' || $voice === '' || $from === '') {
            throw new RuntimeException('Texto, VoiceId y archivo origen son requeridos.');
        }

        if (!in_array($engine, ['standard', 'neural', 'long-form', 'generative'], true)) {
            throw new RuntimeException('Motor de Polly inválido.');
        }

        $origin = $this->locator->requireReadableByKey($userId, $from);
        $realFrom = (string)$origin['_key'];
        [$format, $ext, $contentType] = $this->format($requestedFormat);
        [$dir, $physicalBase, $dest, $visible] = $this->destination($origin, $ext);
        $characters = $this->characters($text);

        $this->assertVoiceSupportsEngine($voice, $engine);

        if ($toS3 === 1) {
            if ($characters > self::ASYNC_BILLED_LIMIT) {
                throw new RuntimeException(
                    'El texto supera el máximo de 100,000 caracteres facturables permitido por Polly para generación asíncrona.'
                );
            }

            // OutputS3KeyPrefix de Polly sólo acepta un subconjunto ASCII y
            // rechaza, entre otros, espacios y acentos. Las carpetas reales del
            // Drive sí pueden contenerlos, por lo que nunca enviamos la ruta del
            // usuario como prefijo temporal. El resultado final se copia luego a
            // la ruta original, conservando nombre/carpeta visibles.
            $tempPrefix = $this->asyncPrefix($userId, $realFrom);
            $params = [
                'Text' => $text,
                'VoiceId' => $voice,
                'Engine' => $engine,
                'OutputFormat' => $format,
                'SampleRate' => $sample,
                'OutputS3BucketName' => $this->bucket,
                'OutputS3KeyPrefix' => $tempPrefix,
            ];

            $res = $this->polly->startSpeechSynthesisTask($params);
            $task = (array)$res->get('SynthesisTask');
            $taskId = trim((string)($task['TaskId'] ?? ''));

            if ($taskId === '') {
                throw new RuntimeException('Polly no devolvió un identificador para la tarea de audio.');
            }

            return [
                'ok' => true,
                'mode' => 'task',
                'task_id' => $taskId,
                'task_status' => (string)($task['TaskStatus'] ?? 'scheduled'),
                'output_uri' => (string)($task['OutputUri'] ?? ''),
                's3_key' => $dest,
                'filename' => basename($dest),
                'nombre' => $visible,
                'ruta' => $dir,
                'contentType' => $contentType,
                'engine_used' => $engine,
                'characters_input' => (int)($task['RequestCharacters'] ?? $characters),
                'source_file_id' => (int)$origin['id_'],
                'message' => 'Audio enviado a Amazon Polly para generación en segundo plano.',
            ];
        }

        if ($characters > self::SYNC_BILLED_LIMIT) {
            throw new RuntimeException(
                'Este texto es demasiado largo para generación inmediata. Activa “Guardar en S3” para procesarlo en segundo plano.'
            );
        }

        $params = [
            'Text' => $text,
            'VoiceId' => $voice,
            'Engine' => $engine,
            'OutputFormat' => $format,
            'SampleRate' => $sample,
        ];
        $res = $this->polly->synthesizeSpeech($params);
        $bytes = (string)$res->get('AudioStream');
        $size = strlen($bytes);

        if ($size <= 0) {
            throw new RuntimeException('Polly no devolvió contenido de audio.');
        }

        return [
            'ok' => true,
            'mode' => 'inline',
            's3_key' => null,
            'filename' => basename($dest),
            'nombre' => $visible,
            'ruta' => $dir,
            'audioBase64' => base64_encode($bytes),
            'contentType' => $contentType,
            'db_status' => 'no_intentado',
            'db_message' => '',
            'db_error' => '',
            'engine_used' => $engine,
            'characters_input' => $characters,
            'output_bytes' => $size,
            'source_file_id' => (int)$origin['id_'],
        ];
    }

    public function taskStatus(int $userId, array $input): array
    {
        $taskId = trim((string)($input['task_id'] ?? $input['taskId'] ?? ''));
        $from = trim((string)($input['from_key'] ?? $input['fromKey'] ?? $input['pollyArchivoKey'] ?? ''));

        if ($taskId === '' || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $taskId)) {
            throw new RuntimeException('Identificador de tarea de Polly inválido.');
        }
        if ($from === '') {
            throw new RuntimeException('Archivo origen requerido para consultar la tarea de Polly.');
        }

        $origin = $this->locator->requireReadableByKey($userId, $from);
        $realFrom = (string)$origin['_key'];
        $res = $this->polly->getSpeechSynthesisTask(['TaskId' => $taskId]);
        $task = (array)$res->get('SynthesisTask');
        $status = (string)($task['TaskStatus'] ?? '');
        $engine = strtolower((string)($task['Engine'] ?? 'standard'));
        $characters = max(0, (int)($task['RequestCharacters'] ?? 0));

        if ($status === '') {
            throw new RuntimeException('Polly no devolvió el estado de la tarea.');
        }

        if ($status === 'failed') {
            return [
                'ok' => true,
                'mode' => 'task',
                'task_id' => $taskId,
                'task_status' => 'failed',
                'error' => (string)($task['TaskStatusReason'] ?? 'La generación de audio falló en Amazon Polly.'),
                'engine_used' => $engine,
                'characters_input' => $characters,
                'source_file_id' => (int)$origin['id_'],
            ];
        }

        if ($status !== 'completed') {
            return [
                'ok' => true,
                'mode' => 'task',
                'task_id' => $taskId,
                'task_status' => $status,
                'engine_used' => $engine,
                'characters_input' => $characters,
                'source_file_id' => (int)$origin['id_'],
                'message' => $status === 'scheduled'
                    ? 'La tarea de audio está programada.'
                    : 'Amazon Polly está generando el audio.',
            ];
        }

        $taskFormat = strtolower((string)($task['OutputFormat'] ?? 'mp3'));
        [$format, $ext, $contentType] = $this->format($taskFormat);
        [$dir, $physicalBase, $dest, $visible] = $this->destination($origin, $ext);
        $expectedPrefix = $this->asyncPrefix($userId, $realFrom) . '.';
        $outputUri = trim((string)($task['OutputUri'] ?? ''));
        $tempKey = $this->outputKeyFromUri($outputUri);

        if (
            $tempKey === '' ||
            !str_starts_with($tempKey, $expectedPrefix) ||
            !str_ends_with($tempKey, '.' . $ext)
        ) {
            throw new RuntimeException('La salida temporal de Polly no corresponde al archivo origen solicitado.');
        }

        $headRequests = 0;
        $finalHead = $this->headIfExists($dest);
        $headRequests++;
        $finalizedNow = false;
        $dbStatus = 'ya_finalizado';
        $size = 0;

        if ($finalHead !== null) {
            $finalMeta = array_change_key_case((array)($finalHead['Metadata'] ?? []), CASE_LOWER);
            if (($finalMeta['polly-task-id'] ?? '') === $taskId) {
                $size = (int)($finalHead['ContentLength'] ?? 0);
            } else {
                $finalHead = null;
            }
        }

        if ($finalHead === null) {
            $tempHead = $this->headIfExists($tempKey);
            $headRequests++;
            if ($tempHead === null) {
                throw new RuntimeException('El audio terminó en Polly, pero no se encontró el archivo temporal en S3.');
            }

            $size = (int)($tempHead['ContentLength'] ?? 0);
            $voice = (string)($task['VoiceId'] ?? '');
            $sample = (string)($task['SampleRate'] ?? '');

            $this->s3->copyObject([
                'Bucket' => $this->bucket,
                'CopySource' => rawurlencode($this->bucket . '/' . $tempKey),
                'Key' => $dest,
                'ACL' => 'private',
                'ContentType' => $contentType,
                'MetadataDirective' => 'REPLACE',
                'Metadata' => [
                    'origin' => $realFrom,
                    'voiceid' => $voice,
                    'engine' => $engine,
                    'format' => $format,
                    'sample_rate' => $sample,
                    'polly-task-id' => $taskId,
                ],
            ]);

            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $tempKey,
            ]);

            $meta = [
                'tipo' => $contentType,
                'servicio' => 'Amazon Polly',
                'engine' => $engine,
                'voiceId' => $voice,
                'format' => $format,
                'sampleRate' => $sample,
                'polly_task_id' => $taskId,
                'polly_task_status' => 'completed',
                'origen' => $realFrom,
                'destino' => $dest,
                'ruta' => $dir,
                'tamano_bytes' => $size,
                'fecha' => date('Y-m-d'),
                'hora' => date('H:i:s'),
            ];

            $dbStatus = $this->generated->upsert($userId, $visible, $dest, $size, $meta, $dir);
            $finalizedNow = true;
        }

        return [
            'ok' => true,
            'mode' => 'task',
            'task_id' => $taskId,
            'task_status' => 'completed',
            'finalized_now' => $finalizedNow,
            's3_key' => $dest,
            'filename' => basename($dest),
            'nombre' => $visible,
            'ruta' => $dir,
            'contentType' => $contentType,
            'db_status' => $dbStatus,
            'engine_used' => $engine,
            'characters_input' => $characters,
            'output_bytes' => $size,
            'source_file_id' => (int)$origin['id_'],
            // La escritura inicial del temporal la realiza Polly en nuestro
            // bucket. Luego Drive hace COPY + DELETE + HEAD para normalizarlo.
            's3_put_requests' => $finalizedNow ? 1 : 0,
            's3_copy_requests' => $finalizedNow ? 1 : 0,
            's3_delete_requests' => $finalizedNow ? 1 : 0,
            's3_head_requests' => $headRequests,
            'message' => 'Audio generado y guardado en la misma carpeta del texto origen.',
        ];
    }

    /**
     * Una tarea asíncrona de Polly no ofrece una API de cancelación. Cuando el
     * usuario la cancela en Drive, esta rutina espera su estado real y elimina
     * únicamente el temporal .arcadecloud/polly correspondiente, sin copiarlo
     * al nombre final ni registrarlo como archivo generado.
     */
    public function discardTask(int $userId, array $input): array
    {
        $taskId = trim((string)($input['task_id'] ?? $input['taskId'] ?? ''));
        $from = trim((string)($input['from_key'] ?? $input['fromKey'] ?? $input['pollyArchivoKey'] ?? ''));

        if ($taskId === '' || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $taskId)) {
            throw new RuntimeException('Identificador de tarea de Polly inválido.');
        }
        if ($from === '') {
            throw new RuntimeException('Archivo origen requerido para limpiar la tarea de Polly.');
        }

        $origin = $this->locator->requireReadableByKey($userId, $from);
        $realFrom = (string)$origin['_key'];
        $res = $this->polly->getSpeechSynthesisTask(['TaskId' => $taskId]);
        $task = (array)$res->get('SynthesisTask');
        $status = strtolower(trim((string)($task['TaskStatus'] ?? '')));

        if ($status === '') {
            throw new RuntimeException('Polly no devolvió el estado de la tarea cancelada.');
        }

        if ($status === 'failed') {
            return [
                'ok' => true,
                'task_id' => $taskId,
                'task_status' => 'failed',
                'cleanup_done' => true,
                'deleted_temp' => false,
            ];
        }

        if ($status !== 'completed') {
            return [
                'ok' => true,
                'task_id' => $taskId,
                'task_status' => $status,
                'cleanup_done' => false,
                'deleted_temp' => false,
            ];
        }

        $taskFormat = strtolower((string)($task['OutputFormat'] ?? 'mp3'));
        [, $ext] = $this->format($taskFormat);
        $expectedPrefix = $this->asyncPrefix($userId, $realFrom) . '.';
        $tempKey = $this->outputKeyFromUri(trim((string)($task['OutputUri'] ?? '')));

        if (
            $tempKey === '' ||
            !str_starts_with($tempKey, $expectedPrefix) ||
            !str_ends_with($tempKey, '.' . $ext)
        ) {
            throw new RuntimeException('La salida temporal cancelada de Polly no corresponde al archivo origen.');
        }

        $exists = $this->headIfExists($tempKey) !== null;
        if ($exists) {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $tempKey,
            ]);
        }

        return [
            'ok' => true,
            'task_id' => $taskId,
            'task_status' => 'completed',
            'cleanup_done' => true,
            'deleted_temp' => $exists,
            'temp_key' => $tempKey,
        ];
    }

    private function destination(array $origin, string $ext): array
    {
        $realFrom = (string)$origin['_key'];
        $dirname = dirname($realFrom);
        $dir = $dirname === '.' ? '' : rtrim($dirname, '/') . '/';
        $physicalBase = pathinfo(basename($realFrom), PATHINFO_FILENAME);
        $dest = $dir . $physicalBase . '.' . $ext;
        $visible = (string)($origin['Nombre'] ?? $physicalBase);
        $visible = (preg_replace('/\.[^.]+$/', '', $visible) ?: $visible) . '.' . $ext;

        return [$dir, $physicalBase, $dest, $visible];
    }

    private function asyncPrefix(int $userId, string $realFrom): string
    {
        $userPart = max(1, $userId);
        $hash = hash('sha256', $realFrom);
        // Sólo caracteres aceptados por OutputS3KeyPrefix y muy por debajo de
        // su máximo de 800 caracteres.
        return '.arcadecloud/polly/u' . $userPart . '/' . $hash;
    }

    private function assertVoiceSupportsEngine(string $voiceId, string $engine): void
    {
        $args = [];
        do {
            $resp = $this->polly->describeVoices($args);
            foreach ((array)($resp['Voices'] ?? []) as $voice) {
                if ((string)($voice['Id'] ?? '') !== $voiceId) {
                    continue;
                }

                $engines = array_map('strtolower', array_map('strval', (array)($voice['SupportedEngines'] ?? [])));
                if (!in_array($engine, $engines, true)) {
                    $supported = $engines !== [] ? implode(', ', $engines) : 'ninguno';
                    throw new RuntimeException(
                        sprintf('La voz %s no soporta el motor %s. Motores disponibles: %s.', $voiceId, $engine, $supported)
                    );
                }
                return;
            }

            $nextToken = trim((string)($resp['NextToken'] ?? ''));
            if ($nextToken !== '') {
                $args['NextToken'] = $nextToken;
            } else {
                unset($args['NextToken']);
            }
        } while ($nextToken !== '');

        throw new RuntimeException('La voz seleccionada no está disponible en Amazon Polly.');
    }

    private function outputKeyFromUri(string $uri): string
    {
        if ($uri === '') {
            return '';
        }

        $path = rawurldecode((string)parse_url($uri, PHP_URL_PATH));
        $path = ltrim($path, '/');
        $bucketPrefix = $this->bucket . '/';
        if (str_starts_with($path, $bucketPrefix)) {
            $path = substr($path, strlen($bucketPrefix));
        }

        return $path;
    }

    private function headIfExists(string $key): ?array
    {
        try {
            return $this->s3->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ])->toArray();
        } catch (AwsException $e) {
            $code = (string)$e->getAwsErrorCode();
            if ($e->getStatusCode() === 404 || in_array($code, ['NotFound', 'NoSuchKey'], true)) {
                return null;
            }
            throw $e;
        }
    }

    private function characters(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function format(string $format): array
    {
        return match ($format) {
            'mp3' => ['mp3', 'mp3', 'audio/mpeg'],
            'ogg_vorbis' => ['ogg_vorbis', 'ogg', 'audio/ogg'],
            'pcm' => ['pcm', 'pcm', 'audio/pcm'],
            default => throw new RuntimeException('Formato de audio no soportado por este flujo de Polly.'),
        };
    }
}
