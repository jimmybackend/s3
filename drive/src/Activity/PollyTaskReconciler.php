<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

use ArcadeCloud\Drive\Aws\PollyFileService;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Finaliza tareas asíncronas de Amazon Polly sin depender del navegador.
 *
 * La tarea se identifica por task_id almacenado en MetadataJson y por el
 * CorrelationId polly:<sha256(task_id)>. El archivo origen se resuelve desde
 * FileS3 usando FileId + user_id, manteniendo MySQL como fuente de verdad.
 */
final class PollyTaskReconciler
{
    public function __construct(
        private mysqli $db,
        private PollyFileService $files,
        private ActivityCostRecorder $activity
    ) {
    }

    public function run(int $limit = 250): array
    {
        $limit = max(1, min(1000, $limit));
        $pending = $this->pendingEvents($limit);
        $stats = [
            'ok' => true,
            'pending' => count($pending),
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'in_progress' => 0,
            'errors' => [],
        ];

        foreach ($pending as $event) {
            try {
                $status = $this->reconcileOne($event);
                if ($status === 'completed') {
                    $stats['completed']++;
                } elseif ($status === 'failed') {
                    $stats['failed']++;
                } elseif ($status === 'cancelled') {
                    $stats['cancelled']++;
                } else {
                    $stats['in_progress']++;
                }
            } catch (Throwable $e) {
                $stats['errors'][] = [
                    'event_id' => (int)($event['id_'] ?? 0),
                    'message' => substr($e->getMessage(), 0, 300),
                ];
            }
        }

        $stats['ok'] = $stats['errors'] === [];
        return $stats;
    }

    private function reconcileOne(array $event): string
    {
        $eventId = (int)($event['id_'] ?? 0);
        $userId = (int)($event['user_id_'] ?? 0);
        $fileId = (int)($event['FileId'] ?? 0);
        $fileKey = trim((string)($event['file_key'] ?? ''));
        $correlation = trim((string)($event['CorrelationId'] ?? ''));
        $metadata = $this->decodeMetadata((string)($event['MetadataJson'] ?? ''));
        $taskId = trim((string)($metadata['task_id'] ?? ''));

        if ($eventId <= 0 || $userId <= 0 || $fileId <= 0 || $fileKey === '') {
            throw new RuntimeException('Evento Polly incompleto para reconciliación.');
        }
        if ($taskId === '' || $correlation === '') {
            throw new RuntimeException('Evento Polly sin task_id/correlación persistente.');
        }

        if (strtolower((string)($metadata['phase'] ?? '')) === 'cancelled') {
            return $this->reconcileCancelled($eventId, $userId, $fileKey, $taskId, $metadata);
        }

        $started = microtime(true);
        $result = $this->files->taskStatus($userId, [
            'task_id' => $taskId,
            'from_key' => $fileKey,
        ]);
        $status = strtolower(trim((string)($result['task_status'] ?? '')));

        if ($status === 'failed') {
            $this->markFailed(
                $eventId,
                $taskId,
                (string)($result['error'] ?? 'La generación de audio falló en Amazon Polly.'),
                $metadata
            );
            return 'failed';
        }

        if ($status !== 'completed') {
            $this->updateRunning($eventId, $taskId, $status, $metadata);
            return $status !== '' ? $status : 'running';
        }

        $engine = strtolower((string)($result['engine_used'] ?? $metadata['engine'] ?? 'standard'));
        $characters = max(
            0,
            (int)($result['characters_input'] ?? 0),
            (int)($metadata['characters'] ?? 0)
        );
        $unit = $this->pollyUnit($engine);

        $this->activity->success(
            $userId,
            'polly',
            'Polly',
            $fileId,
            [$unit => $characters],
            $started,
            [
                'phase' => 'completed',
                'task_id' => $taskId,
                'task_status' => 'completed',
                'engine' => $engine,
                'characters' => $characters,
                'output_name' => (string)($result['nombre'] ?? $metadata['output_name'] ?? 'audio'),
                'reconciled_by' => 'server_timer',
            ],
            $correlation
        );

        if (($result['finalized_now'] ?? false) === true) {
            $this->recordS3Cost($userId, $fileId, $result, $started, $correlation);
        }

        return 'completed';
    }

    private function reconcileCancelled(
        int $eventId,
        int $userId,
        string $fileKey,
        string $taskId,
        array $metadata
    ): string {
        $result = $this->files->discardTask($userId, [
            'task_id' => $taskId,
            'from_key' => $fileKey,
        ]);

        $providerStatus = strtolower((string)($result['task_status'] ?? 'unknown'));
        $cleanupDone = ($result['cleanup_done'] ?? false) === true;
        $next = array_merge($metadata, [
            'phase' => 'cancelled',
            'task_id' => $taskId,
            'task_status' => 'cancelled',
            'provider_status' => $providerStatus,
            'cleanup_pending' => !$cleanupDone,
            'cleanup_done' => $cleanupDone,
            'task_center_updated_at' => gmdate('c'),
            'reconciled_by' => 'server_timer',
        ]);
        if ($cleanupDone) {
            $next['cleanup_done_at'] = gmdate('c');
            $next['deleted_temp'] = ($result['deleted_temp'] ?? false) === true;
        }

        $this->updateMetadata($eventId, $next, false);
        return 'cancelled';
    }

    private function pendingEvents(int $limit): array
    {
        $sql = "SELECT e.id_, e.user_id_, e.FileId, e.CorrelationId, e.MetadataJson, e.CreatedAt,
                       f.Encriptado AS file_key
                FROM DriveActivityEvents e
                LEFT JOIN FileS3 f
                  ON f.id_ = e.FileId AND f.user_id_ = e.user_id_
                WHERE e.Action = 'polly'
                  AND e.Service = 'Polly'
                  AND e.Status = 'ok'
                  AND e.CorrelationId IS NOT NULL
                  AND e.CorrelationId LIKE 'polly:%'
                  AND e.MetadataJson IS NOT NULL
                  AND (
                    e.MetadataJson LIKE '%\"phase\":\"started\"%'
                    OR e.MetadataJson LIKE '%\"phase\":\"running\"%'
                    OR (
                        e.MetadataJson LIKE '%\"phase\":\"cancelled\"%'
                        AND e.MetadataJson LIKE '%\"cleanup_pending\":true%'
                    )
                  )
                ORDER BY e.CreatedAt ASC, e.id_ ASC
                LIMIT " . (int)$limit;

        $result = $this->db->query($sql);
        if (!$result) {
            throw new RuntimeException('No se pudieron consultar tareas Polly pendientes: ' . $this->db->error);
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    private function updateRunning(int $eventId, string $taskId, string $status, array $previous): void
    {
        $metadata = array_merge($previous, [
            'phase' => 'running',
            'task_id' => $taskId,
            'task_status' => $status !== '' ? $status : 'unknown',
            'engine' => (string)($previous['engine'] ?? ''),
            'characters' => max(0, (int)($previous['characters'] ?? 0)),
            'output_name' => (string)($previous['output_name'] ?? 'audio'),
            'task_center_updated_at' => gmdate('c'),
            'reconciled_by' => 'server_timer',
        ]);
        $this->updateMetadata($eventId, $metadata, false);
    }

    private function markFailed(int $eventId, string $taskId, string $reason, array $previous): void
    {
        $metadata = array_merge($previous, [
            'phase' => 'failed',
            'task_id' => $taskId,
            'task_status' => 'failed',
            'engine' => (string)($previous['engine'] ?? ''),
            'characters' => max(0, (int)($previous['characters'] ?? 0)),
            'output_name' => (string)($previous['output_name'] ?? 'audio'),
            'reason' => substr($reason, 0, 160),
            'task_center_updated_at' => gmdate('c'),
            'reconciled_by' => 'server_timer',
        ]);
        $this->updateMetadata($eventId, $metadata, true);
    }

    private function updateMetadata(int $eventId, array $metadata, bool $failed): void
    {
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo serializar el estado Polly.');
        }

        $sql = $failed
            ? "UPDATE DriveActivityEvents SET MetadataJson = ?, Status = 'error' WHERE id_ = ?"
            : "UPDATE DriveActivityEvents SET MetadataJson = ? WHERE id_ = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt || !$stmt->execute([$json, $eventId])) {
            $error = $stmt ? $stmt->error : $this->db->error;
            if ($stmt) $stmt->close();
            throw new RuntimeException('No se pudo actualizar el estado Polly: ' . $error);
        }
        $stmt->close();
    }

    private function recordS3Cost(
        int $userId,
        int $fileId,
        array $result,
        float $started,
        string $correlation
    ): void {
        $this->activity->success(
            $userId,
            'polly',
            'S3',
            $fileId,
            [
                's3.put_request' => max(0, (int)($result['s3_put_requests'] ?? 0)),
                's3.copy_request' => max(0, (int)($result['s3_copy_requests'] ?? 0)),
                's3.delete_request' => max(0, (int)($result['s3_delete_requests'] ?? 0)),
                's3.head_request' => max(0, (int)($result['s3_head_requests'] ?? 0)),
                's3.storage_bytes_delta' => max(0, (int)($result['output_bytes'] ?? 0)),
            ],
            $started,
            [
                'phase' => 'generated_output',
                'task_id' => (string)($result['task_id'] ?? ''),
                'output_bytes' => max(0, (int)($result['output_bytes'] ?? 0)),
                'reconciled_by' => 'server_timer',
            ],
            $correlation
        );
    }

    private function pollyUnit(string $engine): string
    {
        return match ($engine) {
            'neural' => 'polly.neural_character',
            'long-form' => 'polly.long-form_character',
            'generative' => 'polly.generative_character',
            default => 'polly.standard_character',
        };
    }

    private function decodeMetadata(string $json): array
    {
        if ($json === '') return [];
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }
}
