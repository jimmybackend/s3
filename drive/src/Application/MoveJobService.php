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

    public function queueFiles(
        int $userId,
        array $refs,
        string $destination,
        bool $createdDestination = false,
        string $operation = 'move'
    ): array {
        $operation = $this->normalizeOperation($operation);
        $destination = $this->paths->normalizeForUser($destination, $userId);
        $refs = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            $refs
        ))));

        if ($refs === []) {
            throw new RuntimeException('No hay archivos seleccionados.');
        }

        if ($destination !== $this->paths->rootForUser($userId)) {
            $this->folderRecords->requireActive($userId, $destination);
        }

        $allAlreadyThere = $operation === 'move';
        foreach ($refs as $ref) {
            $file = $this->fileRecords->requireByRef($userId, $ref, true);
            if ($this->fileRecords->normalizePrefix((string)($file['Ruta'] ?? '')) !== $destination) {
                $allAlreadyThere = false;
            }
        }
        if ($allAlreadyThere) {
            throw new RuntimeException('El elemento ya está en esta carpeta.');
        }

        return $this->store->create($userId, 'files', [
            'refs' => $refs,
            'destination' => $destination,
            'created_destination' => $createdDestination,
            'operation' => $operation,
        ]);
    }

    public function queueFolder(
        int $userId,
        string $origin,
        string $destination,
        string $operation = 'move'
    ): array {
        $operation = $this->normalizeOperation($operation);
        $root = $this->paths->rootForUser($userId);
        $origin = $this->paths->normalizeForUser($origin, $userId);
        $destination = $this->paths->normalizeForUser($destination, $userId);

        if ($origin === $root) {
            throw new RuntimeException(
                $operation === 'copy'
                    ? 'No se puede copiar la carpeta raíz del usuario.'
                    : 'No se puede mover la carpeta raíz del usuario.'
            );
        }

        $this->folderRecords->requireActive($userId, $origin);
        $this->folderRecords->requireActive($userId, $destination);

        return $this->store->create($userId, 'folder', [
            'origin' => $origin,
            'destination' => $destination,
            'operation' => $operation,
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
        $operation = $this->normalizeOperation((string)($payload['operation'] ?? 'move'));
        $copying = $operation === 'copy';

        try {
            if ($type === 'files') {
                $refs = is_array($payload['refs'] ?? null) ? array_values($payload['refs']) : [];
                $destination = (string)($payload['destination'] ?? '');
                $total = count($refs);
                $processed = 0;
                $derivedListRequests = 0;
                $derivedCopyRequests = 0;
                $derivedDeleteRequests = 0;

                foreach ($refs as $ref) {
                    $current = $this->store->get($jobId);
                    if ((string)($current['status'] ?? '') === 'cancel_requested') {
                        return $this->cancelFilesJob($jobId, $processed, $total, $destination, $operation);
                    }

                    if ($copying) {
                        $mutation = $this->files->copy($userId, is_int($ref) ? $ref : (string)$ref, $destination);
                    } else {
                        $mutation = $this->files->move($userId, is_int($ref) ? $ref : (string)$ref, $destination);
                    }
                    $derived = is_array($mutation['derived_assets'] ?? null) ? $mutation['derived_assets'] : [];
                    $derivedListRequests += max(0, (int)($derived['list_requests'] ?? 0));
                    $derivedCopyRequests += max(0, (int)($derived['copy_requests'] ?? 0));
                    $derivedDeleteRequests += max(0, (int)($derived['delete_requests'] ?? 0));
                    $processed++;

                    // La solicitud de detención puede llegar mientras S3 procesa
                    // el objeto. Revisamos antes de sobrescribir el estado.
                    $afterTransfer = $this->store->get($jobId);
                    if ((string)($afterTransfer['status'] ?? '') === 'cancel_requested') {
                        return $this->cancelFilesJob($jobId, $processed, $total, $destination, $operation);
                    }

                    $this->store->update($jobId, [
                        'status' => 'running',
                        'message' => ($copying ? 'Copiando' : 'Moviendo') . ' archivos · ' . $processed . ' de ' . $total,
                        'result' => [
                            'total' => $processed,
                            'requested_total' => $total,
                            'ruta_nueva' => $destination,
                            'operation' => $operation,
                            'derived_list_requests' => $derivedListRequests,
                            'derived_copy_requests' => $derivedCopyRequests,
                            'derived_delete_requests' => $derivedDeleteRequests,
                            'estado' => 'parcial',
                        ],
                    ]);
                }

                $result = [
                    'total' => $processed,
                    'requested_total' => $total,
                    'ruta_nueva' => $destination,
                    'operation' => $operation,
                    'derived_list_requests' => $derivedListRequests,
                    'derived_copy_requests' => $derivedCopyRequests,
                    'derived_delete_requests' => $derivedDeleteRequests,
                    'estado' => $copying ? 'copiados' : 'movidos',
                ];
            } elseif ($type === 'folder') {
                $current = $this->store->get($jobId);
                if ((string)($current['status'] ?? '') === 'cancel_requested') {
                    $cancelled = $this->store->update($jobId, [
                        'status' => 'cancelled',
                        'message' => ($copying ? 'Copia' : 'Movimiento') . ' de carpeta cancelado antes de iniciar.',
                        'result' => null,
                        'error' => null,
                    ]);
                    $cancelled['_worker_claimed'] = true;
                    return $cancelled;
                }

                $origin = (string)($payload['origin'] ?? '');
                $destination = (string)($payload['destination'] ?? '');
                $result = $copying
                    ? $this->folders->copy($userId, $origin, $destination)
                    : $this->folders->move($userId, $origin, $destination);
                $result['operation'] = $operation;
            } else {
                throw new RuntimeException('Tipo de tarea de transferencia inválido.');
            }

            $completed = $this->store->update($jobId, [
                'status' => 'completed',
                'message' => $type === 'folder'
                    ? ($copying ? 'Carpeta copiada correctamente.' : 'Carpeta movida correctamente.')
                    : ($copying ? 'Archivo(s) copiado(s) correctamente.' : 'Archivo(s) movido(s) correctamente.'),
                'result' => $result,
                'error' => null,
            ]);
            $completed['_worker_claimed'] = true;
            return $completed;
        } catch (\Throwable $error) {
            $current = $this->store->get($jobId);
            $failed = $this->store->update($jobId, [
                'status' => 'failed',
                'message' => $copying
                    ? 'No se pudo completar la copia.'
                    : 'No se pudo completar el movimiento.',
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

    private function cancelFilesJob(
        string $jobId,
        int $processed,
        int $total,
        string $destination,
        string $operation
    ): array {
        $copying = $operation === 'copy';
        $cancelled = $this->store->update($jobId, [
            'status' => 'cancelled',
            'message' => ($copying ? 'Copia' : 'Movimiento') . ' detenido de forma segura.',
            'result' => [
                'total' => $processed,
                'requested_total' => $total,
                'ruta_nueva' => $destination,
                'operation' => $operation,
                'estado' => 'cancelado',
            ],
            'error' => null,
        ]);
        $cancelled['_worker_claimed'] = true;
        return $cancelled;
    }

    private function normalizeOperation(string $operation): string
    {
        $operation = strtolower(trim($operation));
        if ($operation === '') $operation = 'move';
        if (!in_array($operation, ['move', 'copy'], true)) {
            throw new RuntimeException('Operación de transferencia inválida.');
        }
        return $operation;
    }
}
