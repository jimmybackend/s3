<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Textract\TextractClient;
use RuntimeException;

final class TextractFileService
{
    private const EXTENSIONS = ['jpg','jpeg','png','tif','tiff','pdf'];
    private const METADATA_TEXT_LIMIT = 24 * 1024;

    public function __construct(
        private FileRecordLocator $locator,
        private FileMetadataRepository $metadata,
        private TextractClient $client,
        private string $bucket
    ) {
    }

    public function extract(int $userId, string $key): array
    {
        $row = $this->locator->requireReadableByKey($userId, $key);
        $real = (string)$row['_key'];
        $ext = strtolower((string)pathinfo((string)($row['Nombre'] ?? $real), PATHINFO_EXTENSION));

        if (!in_array($ext, self::EXTENSIONS, true)) {
            throw new RuntimeException('Extensión no soportada para Textract');
        }

        $result = $this->client->detectDocumentText([
            'Document' => [
                'S3Object' => [
                    'Bucket' => $this->bucket,
                    'Name' => $real,
                ],
            ],
        ]);

        $lines = [];
        $pageCount = 0;
        foreach ((array)($result['Blocks'] ?? []) as $block) {
            $blockType = (string)($block['BlockType'] ?? '');
            if ($blockType === 'PAGE') {
                $pageCount++;
            }
            if ($blockType === 'LINE' && isset($block['Text'])) {
                $lines[] = (string)$block['Text'];
            }
        }
        $pageCount = max(1, $pageCount);

        $fullText = implode("\n", $lines);
        [$metadataText, $metadataTruncated] = $this->metadataText($fullText);

        // El análisis pertenece al catálogo MySQL. El objeto físico de S3 se mantiene intacto.
        $this->metadata->merge(
            $userId,
            (int)$row['id_'],
            'Textract',
            [
                'Nombre' => (string)($row['Nombre'] ?? ''),
                'Ruta' => (string)($row['Ruta'] ?? ''),
                'line_count' => count($lines),
                'page_count' => $pageCount,
                'text' => $metadataText,
                'text_truncated' => $metadataTruncated,
                'bytes_extracted' => strlen($fullText),
            ]
        );

        return [
            'ok' => true,
            'archivo' => $real,
            'file_id' => (int)$row['id_'],
            'texto' => $lines,
            'textoJ' => $fullText,
            'page_count' => $pageCount,
            'saved' => true,
        ];
    }

    public function extractText(int $userId, string $key): string
    {
        return (string)$this->extract($userId, $key)['textoJ'];
    }

    private function metadataText(string $text): array
    {
        if (strlen($text) <= self::METADATA_TEXT_LIMIT) {
            return [$text, false];
        }

        if (function_exists('mb_strcut')) {
            return [mb_strcut($text, 0, self::METADATA_TEXT_LIMIT, 'UTF-8'), true];
        }

        $cut = substr($text, 0, self::METADATA_TEXT_LIMIT);
        if (function_exists('iconv')) {
            $cut = (string)iconv('UTF-8', 'UTF-8//IGNORE', $cut);
        }

        return [$cut, true];
    }
}
