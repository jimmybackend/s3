<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\FileRecordRepository;
use ArcadeCloud\Drive\Storage\FolderMutationRepository;
use ArcadeCloud\Drive\Storage\MoveJobStore;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use RuntimeException;

final class MoveJobService
{
    public function __construct(
        private FileMutationService $files,
        private FolderMutationService $folders,
        private FileRecordRepository $fileRecords,
        private FolderMutationRepository $folderRecords,
        private UserStoragePath $paths,
        private MoveJobStore $store
    ) {
    }

    public function queueFiles(int $userId, array $refs, string $destination): array
    {
        $destination = $this->paths->normalizeForUser($destination, $userId);
        $refs = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            $refs
        ))));

        if ($refs === []) {
            throw new RuntimeException('No hay archivos seleccionados.');
        }

        foreach ($refs as $ref) {
            $this->fileRecords->requireByRef($userId, $ref, true);
        }

        return $this->store->create($userId, 'files', [
            'refs' => $refs,
            'destination' => $destination,
        ]);
    }

    public function queueFolder(int $userId, string $origin, string $destination): array
    {
        $root = $this->paths->rootForUser($userId);
        $origin = $this->paths->normalizeForUser($origin, $userId);
        $destination = $this->paths->normalizeForUser($destination, $userId);

        if ($origin === $root) {
            throw new RuntimeException('No se puede mover la carpeta raíz del usuario.');
        }

        $this->folderRecords->requireActive($userId, $origin);
        $this->folderRecords->requireActive($userId, $destination);

        return $this->store->create($userId, 'folder', [
            'origin' => $origin,
            'destination' => $destination,
        ]);
    }

    public function run(string $jobId): void
    {
        $job = $this->store->get($jobId);
        if (($job['status'] ?? '') !== 'queued') {
            return;
        }

        $this->store->update($jobId, [
            'status' => 'running',
            'message' => 'Movimiento en proceso.',
            'error' => null,
        ]);

        try {
            $userId = (int)($job['user_id'] ?? 0);
            $type = (string)($job['type'] ?? '');
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

            if ($type === 'files') {
                $refs = is_array($payload['refs'] ?? null) ? $payload['refs'] : [];
                $destination = (string)($payload['destination'] ?? '');
                $result = $this->files->moveMany($userId, $refs, $destination);
            } elseif ($type === 'folder') {
                $origin = (string)($payload['origin'] ?? '');
                $destination = (string)($payload['destination'] ?? '');
                $result = $this->folders->move($userId, $origin, $destination);
            } else {
                throw new RuntimeException('Tipo de tarea de movimiento inválido.');
            }

            $this->store->update($jobId, [
                'status' => 'completed',
                'message' => $type === 'folder'
                    ? 'Carpeta movida correctamente.'
                    : 'Archivo(s) movido(s) correctamente.',
                'result' => $result,
                'error' => null,
            ]);
        } catch (\Throwable $error) {
            $this->store->update($jobId, [
                'status' => 'failed',
                'message' => 'No se pudo completar el movimiento.',
                'error' => $error->getMessage(),
            ]);
        }
    }

    public function statusForUser(int $userId, string $jobId): array
    {
        return $this->store->getForUser($userId, $jobId);
    }
}
