<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use RuntimeException;

final class MediaProcessingService
{
    private const VIDEO = ['mp4','webm','mov','avi','mkv','m4v','ogv','ogg'];
    private const AUDIO = ['mp3','wav','ogg','opus','m4a','aac','flac','amr','webm'];
    public const MAX_SOURCE_BYTES = 8 * 1024 * 1024 * 1024;

    public function __construct(
        private FileRecordLocator $locator,
        private MediaProcessingJobRepository $jobs,
        private MediaWorkerNodeService $node
    ) {
    }

    public function enqueue(int $userId, array $input): array
    {
        $key = trim((string)($input['archivo'] ?? ''));
        $operation = strtolower(trim((string)($input['operation'] ?? '')));
        if ($key === '') throw new RuntimeException('Falta el archivo a procesar.');

        $source = $this->locator->requireReadableByKey($userId, $key);
        $ext = strtolower((string)pathinfo((string)($source['Nombre'] ?? $key), PATHINFO_EXTENSION));
        $sourceBytes = max(0, (int)($source['Tamano'] ?? 0));
        if ($sourceBytes > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('El procesamiento multimedia admite archivos de hasta 8 GB.');
        }

        $parts = max(1, (int)($input['parts'] ?? 1));
        $before = max(0, min(60, (int)($input['overlap_before'] ?? 10)));
        $after = max(0, min(60, (int)($input['overlap_after'] ?? 10)));

        if ($operation === 'split_video') {
            if (!in_array($ext, self::VIDEO, true)) {
                throw new RuntimeException('Este archivo no es un video soportado para división.');
            }
            $this->assertParts($parts);
        } elseif ($operation === 'split_audio') {
            if (!in_array($ext, self::AUDIO, true)) {
                throw new RuntimeException('Este archivo no es un audio soportado para división.');
            }
            $this->assertParts($parts);
        } elseif ($operation === 'extract_mp3') {
            if (!in_array($ext, self::VIDEO, true)) {
                throw new RuntimeException('La extracción MP3 requiere un video soportado.');
            }
            $parts = 1;
            $before = 0;
            $after = 0;
        } else {
            throw new RuntimeException('Operación multimedia no soportada.');
        }

        $authorizedStart = $this->boolValue($input['authorize_node_start'] ?? false);
        $node = $this->node->prepareForWork($userId, $authorizedStart);

        $job = $this->jobs->enqueue(
            $userId,
            $source,
            $operation,
            $parts,
            $before,
            $after
        );

        return [
            'ok' => true,
            'job' => $job,
            'message' => 'Tarea multimedia enviada al nodo de procesamiento. El archivo original se conservará intacto.',
            'max_source_bytes' => self::MAX_SOURCE_BYTES,
            'node' => $node,
        ];
    }

    public function recent(int $userId): array
    {
        return ['ok' => true, 'jobs' => $this->jobs->recentForUser($userId)];
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string)$value)), ['1','true','yes','on','si','sí'], true);
    }

    private function assertParts(int $parts): void
    {
        if ($parts < 2 || $parts > 50) {
            throw new RuntimeException('La cantidad de partes debe estar entre 2 y 50.');
        }
    }
}
