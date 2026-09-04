<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Comprehend\ComprehendClient;
use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

final class ComprehendFileService
{
    private const TEXT_EXTENSIONS = [
        'txt','jas','md','markdown','csv','json','html','htm','xml','sql','log',
        'srt','vtt','php','phtml','js','mjs','css','py','ini','cfg','conf','yaml','yml'
    ];

    private const LANGUAGE_CODES = ['en','es','fr','de','it','pt','ar','hi','ja','ko','zh','zh-TW'];

    private FileRecordLocator $locator;

    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private ComprehendClient $comprehend,
        private string $bucket
    ) {
        $this->locator = new FileRecordLocator($db);
    }

    public function analyze(int $userId, string $key): array
    {
        $row = $this->locator->requireReadableByKey($userId, $key);
        $realKey = (string)$row['_key'];
        $ext = strtolower((string)pathinfo((string)($row['Nombre'] ?? $realKey), PATHINFO_EXTENSION));

        if (!in_array($ext, self::TEXT_EXTENSIONS, true)) {
            throw new RuntimeException('Amazon Comprehend se habilita aquí para archivos de texto y código.');
        }

        $object = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $realKey,
        ]);
        $text = (string)$object['Body'];
        if ($text === '') {
            throw new RuntimeException('El archivo está vacío.');
        }

        if (in_array($ext, ['html', 'htm'], true)) {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = str_replace("\0", '', $text);
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('No se encontró texto analizable.');
        }

        $originalBytes = strlen($text);
        $truncated = $originalBytes > 90000;
        if ($truncated) {
            if (function_exists('mb_strcut')) {
                $text = mb_strcut($text, 0, 90000, 'UTF-8');
            } else {
                $text = substr($text, 0, 90000);
                if (function_exists('iconv')) {
                    $text = (string)iconv('UTF-8', 'UTF-8//IGNORE', $text);
                }
            }
        }

        if (strlen($text) < 20) {
            throw new RuntimeException('El texto es demasiado corto para un análisis útil.');
        }

        $warnings = [];
        $languages = [];
        $language = null;

        try {
            $langResp = $this->comprehend->detectDominantLanguage(['Text' => $text]);
            $languages = array_map(static function (array $item): array {
                return [
                    'code' => (string)($item['LanguageCode'] ?? ''),
                    'score' => isset($item['Score']) ? (float)$item['Score'] : null,
                ];
            }, array_slice((array)$langResp->get('Languages'), 0, 5));
            $language = $languages[0]['code'] ?? null;
        } catch (\Throwable $e) {
            $warnings[] = 'No se pudo detectar automáticamente el idioma.';
        }

        if (!in_array($language, self::LANGUAGE_CODES, true)) {
            $warnings[] = 'El idioma detectado no está cubierto por todas las operaciones preentrenadas de este análisis.';
        }

        $result = [
            'record_id' => (int)$row['id_'],
            'key' => $realKey,
            'nombre' => (string)($row['Nombre'] ?? basename($realKey)),
            'language' => $language,
            'languages' => $languages,
            'truncated' => $truncated,
            'bytes_original' => $originalBytes,
            'bytes_analyzed' => strlen($text),
            'sentiment' => null,
            'sentiment_scores' => [],
            'key_phrases' => [],
            'entities' => [],
            'pii' => [],
            'warnings' => $warnings,
        ];

        if (in_array($language, self::LANGUAGE_CODES, true)) {
            $this->fillAnalysis($result, $text, $language);
        }

        $this->storeMetadata($userId, $row, $result);
        unset($result['record_id']);
        return $result;
    }

    private function fillAnalysis(array &$result, string $text, string $language): void
    {
        try {
            $response = $this->comprehend->detectSentiment([
                'Text' => $text,
                'LanguageCode' => $language,
            ]);
            $result['sentiment'] = (string)$response->get('Sentiment');
            $result['sentiment_scores'] = (array)$response->get('SentimentScore');
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Sentimiento no disponible.';
        }

        try {
            $response = $this->comprehend->detectKeyPhrases([
                'Text' => $text,
                'LanguageCode' => $language,
            ]);
            $phrases = [];
            foreach (array_slice((array)$response->get('KeyPhrases'), 0, 25) as $item) {
                $phrases[] = [
                    'text' => (string)($item['Text'] ?? ''),
                    'score' => isset($item['Score']) ? (float)$item['Score'] : null,
                ];
            }
            $result['key_phrases'] = $phrases;
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Frases clave no disponibles.';
        }

        try {
            $response = $this->comprehend->detectEntities([
                'Text' => $text,
                'LanguageCode' => $language,
            ]);
            $entities = [];
            foreach (array_slice((array)$response->get('Entities'), 0, 30) as $item) {
                $entities[] = [
                    'text' => (string)($item['Text'] ?? ''),
                    'type' => (string)($item['Type'] ?? ''),
                    'score' => isset($item['Score']) ? (float)$item['Score'] : null,
                ];
            }
            $result['entities'] = $entities;
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Entidades no disponibles.';
        }

        try {
            $response = $this->comprehend->detectPiiEntities([
                'Text' => $text,
                'LanguageCode' => $language,
            ]);
            $pii = [];
            foreach (array_slice((array)$response->get('Entities'), 0, 30) as $item) {
                $pii[] = [
                    'type' => (string)($item['Type'] ?? ''),
                    'score' => isset($item['Score']) ? (float)$item['Score'] : null,
                    'begin' => isset($item['BeginOffset']) ? (int)$item['BeginOffset'] : null,
                    'end' => isset($item['EndOffset']) ? (int)$item['EndOffset'] : null,
                ];
            }
            $result['pii'] = $pii;
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Detección de PII no disponible.';
        }
    }

    private function storeMetadata(int $userId, array $row, array $analysis): void
    {
        $meta = [];
        $raw = trim((string)($row['Metadatos'] ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $stored = $analysis;
        unset($stored['record_id']);
        $stored['ts'] = date('c');
        $meta['Comprehend'] = $stored;

        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudieron serializar los metadatos de Comprehend.');
        }

        $id = (int)$row['id_'];
        $stmt = $this->db->prepare('UPDATE FileS3 SET Metadatos = ? WHERE id_ = ? AND user_id_ = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la actualización de metadatos: ' . $this->db->error);
        }
        $stmt->bind_param('sii', $json, $id, $userId);
        $stmt->execute();
        $stmt->close();
    }
}
