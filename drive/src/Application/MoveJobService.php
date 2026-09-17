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

    public function queueFiles(int $userId, array $refs, string $destination, bool $createdDestination = false): array
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
            'created_destination' => $createdDestination,
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

    /**
     * Ejecuta un job reclamándolo atómicamente.
     *
     * Para lotes de archivos la cancelación es cooperativa entre objetos: nunca
     * interrumpe una copia S3 a mitad. Para carpetas sólo se puede cancelar antes
     * de que empiece la mutación recursiva, porque cortarla a mitad dejaría una
     * operación parcialmente aplicada difícil de revertir con seguridad.
     */
    public function run(string $jobId): array
    {
        $job = $this->store->claimQueued($jobId);
        if ($job === null) {
            $current = $this->store->get($jobId);
            $current['_worker_claimed'] = false;
            return $current;
        }

        $userId = (int)($job['user_id'] ?? 0);
        $type = (string)($job['type'] ?? '');
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        try {
            if ($type === 'files') {
                $refs = is_array($payload['refs'] ?? null) ? array_values($payload['refs']) : [];
                $destination = (string)($payload['destination'] ?? '');
                $total = count($refs);
                $moved = 0;

                foreach ($refs as $ref) {
                    $current = $this->store->get($jobId);
                    if ((string)($current['status'] ?? '') === 'cancel_requested') {
                        return $this->cancelFilesJob($jobId, $moved, $total, $destination);
                    }

                    $this->files->move($userId, is_int($ref) ? $ref : (string)$ref, $destination);
                    $moved++;

                    // La solicitud de detención puede llegar mientras S3 copia el
                    // archivo. Revisamos de nuevo antes de escribir "running" para
                    // no perderla por una carrera de estados.
                    $afterMove = $this->store->get($jobId);
                    if ((string)($afterMove['status'] ?? '') === 'cancel_requested') {
                        return $this->cancelFilesJob($jobId, $moved, $total, $destination);
                    }

                    $this->store->update($jobId, [
                        'status' => 'running',
                        'message' => 'Moviendo archivos · ' . $moved . ' de ' . $total,
                        'result' => [
                            'total' => $moved,
                            'requested_total' => $total,
                            'ruta_nueva' => $destination,
                            'estado' => 'parcial',
                        ],
                    ]);
                }

                $result = [
                    'total' => $moved,
                    'requested_total' => $total,
                    'ruta_nueva' => $destination,
                    'estado' => 'movidos',
                ];
            } elseif ($type === 'folder') {
                $current = $this->store->get($jobId);
                if ((string)($current['status'] ?? '') === 'cancel_requested') {
                    $cancelled = $this->store->update($jobId, [
                        'status' => 'cancelled',
                        'message' => 'Movimiento de carpeta cancelado antes de iniciar.',
                        'result' => null,
                        'error' => null,
                    ]);
                    $cancelled['_worker_claimed'] = true;
                    return $cancelled;
                }

                $origin = (string)($payload['origin'] ?? '');
                $destination = (string)($payload['destination'] ?? '');
                $result = $this->folders->move($userId, $origin, $destination);
            } else {
                throw new RuntimeException('Tipo de tarea de movimiento inválido.');
            }

            $completed = $this->store->update($jobId, [
                'status' => 'completed',
                'message' => $type === 'folder'
                    ? 'Carpeta movida correctamente.'
                    : 'Archivo(s) movido(s) correctamente.',
                'result' => $result,
                'error' => null,
            ]);
            $completed['_worker_claimed'] = true;
            return $completed;
        } catch (\Throwable $error) {
            $current = $this->store->get($jobId);
            $failed = $this->store->update($jobId, [
                'status' => 'failed',
                'message' => 'No se pudo completar el movimiento.',
                'result' => is_array($current['result'] ?? null) ? $current['result'] : null,
                'error' => $error->getMessage(),
            ]);
            $failed['_worker_claimed'] = true;
            return $failed;
        }
    }

    public function statusForUser(int $userId, string $jobId): array
    {
        return $this->store->getForUser($userId, $jobId);
    }

    private function cancelFilesJob(string $jobId, int $moved, int $total, string $destination): array
    {
        $cancelled = $this->store->update($jobId, [
            'status' => 'cancelled',
            'message' => 'Movimiento detenido de forma segura.',
            'result' => [
                'total' => $moved,
                'requested_total' => $total,
                'ruta_nueva' => $destination,
                'estado' => 'cancelado',
            ],
            'error' => null,
        ]);
        $cancelled['_worker_claimed'] = true;
        return $cancelled;
    }
}
