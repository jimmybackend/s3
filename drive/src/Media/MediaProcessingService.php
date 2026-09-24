<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use RuntimeException;

final class MediaProcessingService
{
    private const VIDEO = ['mp4','webm','mov','avi','mkv','m4v','ogv','ogg'];
    private const AUDIO = ['mp3','wav','ogg','opus','m4a','aac','flac','amr','webm'];

    public function __construct(
        private FileRecordLocator $locator,
        private MediaProcessingJobRepository $jobs
    ) {
    }

    public function enqueue(int $userId, array $input): array
    {
        $key = trim((string)($input['archivo'] ?? ''));
        $operation = strtolower(trim((string)($input['operation'] ?? '')));
        if ($key === '') throw new RuntimeException('Falta el archivo a procesar.');

        $source = $this->locator->requireReadableByKey($userId, $key);
        $ext = strtolower((string)pathinfo((string)($source['Nombre'] ?? $key), PATHINFO_EXTENSION));

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
        ];
    }

    public function recent(int $userId): array
    {
        return ['ok' => true, 'jobs' => $this->jobs->recentForUser($userId)];
    }

    private function assertParts(int $parts): void
    {
        if ($parts < 2 || $parts > 50) {
            throw new RuntimeException('La cantidad de partes debe estar entre 2 y 50.');
        }
    }
}
