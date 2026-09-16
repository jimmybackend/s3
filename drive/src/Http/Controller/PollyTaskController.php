<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\PollyFileService;
use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;

final class PollyTaskController extends AbstractJsonController
{
    public function status(): never
    {
        $this->requirePost();
        $userId = 0;
        $started = microtime(true);

        try {
            $userId = $this->guardAuthenticated();
            $result = $this->service()->taskStatus($userId, $this->request->allPost());
            $status = strtolower((string)($result['task_status'] ?? ''));

            if ($status === 'completed') {
                $this->repairCatalogIfNeeded($userId, $result);
                $this->recordLifecycleCompleted($userId, $result, $started);
                $this->recordFinalizationCost($userId, $result, $started);
            } elseif ($status === 'failed') {
                $this->markLifecycleFailed($userId, $result);
            } else {
                $this->touchLifecycleRunning($userId, $result);
            }

            JsonResponse::send($result);
        } catch (\Throwable $e) {
            if ($userId > 0) {
                try {
                    ActivityCostRecorder::fromDatabase($this->app->db())->failure(
                        $userId,
                        'polly_task_status',
                        'Polly',
                        $started
                    );
                } catch (\Throwable) {
                }
            }

            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function tasks(): never
    {
        try {
            $userId = $this->guardAuthenticated();
            $stmt = $this->app->db()->prepare(
                "SELECT e.id_, e.FileId, e.EstimatedCost, e.Currency, e.PricingState, e.Status,
                        e.MetadataJson, e.CreatedAt, f.Nombre AS file_name
                 FROM DriveActivityEvents e
                 LEFT JOIN FileS3 f
                   ON f.id_ = e.FileId AND f.user_id_ = e.user_id_
                 WHERE e.user_id_ = ?
                   AND e.Action = 'polly'
                   AND e.Service = 'Polly'
                   AND e.CorrelationId LIKE 'polly:%'
                   AND e.CreatedAt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
                 ORDER BY e.CreatedAt DESC, e.id_ DESC
                 LIMIT 30"
            );
            if (!$stmt || !$stmt->execute([$userId])) {
                $error = $stmt ? $stmt->error : $this->app->db()->error;
                if ($stmt) $stmt->close();
                throw new RuntimeException('No se pudieron consultar tareas Polly: ' . $error);
            }

            $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?? [];
            $stmt->close();
            $tasks = [];

            foreach ($rows as $row) {
                $meta = json_decode((string)($row['MetadataJson'] ?? ''), true);
                $meta = is_array($meta) ? $meta : [];
                $taskId = trim((string)($meta['task_id'] ?? ''));
                if ($taskId === '') continue;

                $phase = strtolower((string)($meta['phase'] ?? 'started'));
                $taskStatus = strtolower((string)($meta['task_status'] ?? 'scheduled'));
                if ($phase === 'completed') $taskStatus = 'completed';
                if ($phase === 'failed' || (string)($row['Status'] ?? '') === 'error') $taskStatus = 'failed';

                $tasks[] = [
                    'task_id' => $taskId,
                    'status' => $taskStatus,
                    'phase' => $phase,
                    'file_id' => (int)($row['FileId'] ?? 0),
                    'file_name' => (string)($row['file_name'] ?? $meta['output_name'] ?? 'Audio Polly'),
                    'output_name' => (string)($meta['output_name'] ?? ''),
                    'engine' => (string)($meta['engine'] ?? ''),
                    'characters' => max(0, (int)($meta['characters'] ?? 0)),
                    'estimated_cost' => $row['EstimatedCost'] !== null ? (float)$row['EstimatedCost'] : null,
                    'currency' => (string)($row['Currency'] ?? 'USD'),
                    'pricing_state' => (string)($row['PricingState'] ?? ''),
                    'created_at' => (string)($row['CreatedAt'] ?? ''),
                    'reason' => (string)($meta['reason'] ?? ''),
                    'reconciled_by' => (string)($meta['reconciled_by'] ?? ''),
                ];
            }

            JsonResponse::send(['ok' => true, 'tasks' => $tasks]);
        } catch (\Throwable $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function service(): PollyFileService
    {
        return new PollyFileService(
            new FileRecordLocator($this->app->db()),
            new GeneratedFileRepository($this->app->db()),
            $this->app->s3(),
            \Config::getPolly(),
            $this->app->bucket()
        );
    }

    private function repairCatalogIfNeeded(int $userId, array &$result): void
    {
        if (($result['db_status'] ?? '') !== 'ya_finalizado') {
            return;
        }

        $key = trim((string)($result['s3_key'] ?? ''));
        $name = trim((string)($result['nombre'] ?? ''));
        $route = (string)($result['ruta'] ?? '');
        $size = max(0, (int)($result['output_bytes'] ?? 0));

        if ($key === '' || $name === '') {
            return;
        }

        $meta = [
            'tipo' => (string)($result['contentType'] ?? 'audio/mpeg'),
            'servicio' => 'Amazon Polly',
            'engine' => (string)($result['engine_used'] ?? 'standard'),
            'polly_task_id' => (string)($result['task_id'] ?? ''),
            'polly_task_status' => 'completed',
            'destino' => $key,
            'ruta' => $route,
            'tamano_bytes' => $size,
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
        ];

        $result['db_status'] = (new GeneratedFileRepository($this->app->db()))->upsert(
            $userId,
            $name,
            $key,
            $size,
            $meta,
            $route
        );
    }

    private function recordLifecycleCompleted(int $userId, array $result, float $started): void
    {
        $taskId = (string)($result['task_id'] ?? '');
        if ($taskId === '') return;
        $engine = strtolower((string)($result['engine_used'] ?? 'standard'));
        $unit = match ($engine) {
            'neural' => 'polly.neural_character',
            'long-form' => 'polly.long-form_character',
            'generative' => 'polly.generative_character',
            default => 'polly.standard_character',
        };

        ActivityCostRecorder::fromDatabase($this->app->db())->success(
            $userId,
            'polly',
            'Polly',
            ((int)($result['source_file_id'] ?? 0)) > 0 ? (int)$result['source_file_id'] : null,
            [$unit => max(0, (int)($result['characters_input'] ?? 0))],
            $started,
            [
                'phase' => 'completed',
                'task_id' => $taskId,
                'task_status' => 'completed',
                'engine' => $engine,
                'characters' => max(0, (int)($result['characters_input'] ?? 0)),
                'output_name' => (string)($result['nombre'] ?? $result['filename'] ?? 'audio'),
                'reconciled_by' => 'browser_or_api',
            ],
            ActivityCostRecorder::correlation('polly', $taskId)
        );
    }

    private function touchLifecycleRunning(int $userId, array $result): void
    {
        $taskId = trim((string)($result['task_id'] ?? ''));
        if ($taskId === '') return;
        $correlation = ActivityCostRecorder::correlation('polly', $taskId);
        if ($correlation === null) return;

        $stmt = $this->app->db()->prepare(
            "SELECT id_, MetadataJson FROM DriveActivityEvents
             WHERE user_id_ = ? AND Action = 'polly' AND Service = 'Polly' AND CorrelationId = ?
             LIMIT 1"
        );
        if (!$stmt || !$stmt->execute([$userId, $correlation])) {
            if ($stmt) $stmt->close();
            return;
        }
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        if (!$row) return;

        $meta = json_decode((string)($row['MetadataJson'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $meta['phase'] = 'running';
        $meta['task_id'] = $taskId;
        $meta['task_status'] = (string)($result['task_status'] ?? 'running');
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return;

        $update = $this->app->db()->prepare('UPDATE DriveActivityEvents SET MetadataJson = ? WHERE id_ = ?');
        if ($update) {
            $update->execute([$json, (int)$row['id_']]);
            $update->close();
        }
    }

    private function markLifecycleFailed(int $userId, array $result): void
    {
        $taskId = trim((string)($result['task_id'] ?? ''));
        if ($taskId === '') return;
        $correlation = ActivityCostRecorder::correlation('polly', $taskId);
        if ($correlation === null) return;

        $metadata = json_encode([
            'phase' => 'failed',
            'task_id' => $taskId,
            'task_status' => 'failed',
            'engine' => (string)($result['engine_used'] ?? ''),
            'characters' => max(0, (int)($result['characters_input'] ?? 0)),
            'reason' => substr((string)($result['error'] ?? 'Polly falló.'), 0, 160),
            'reconciled_by' => 'browser_or_api',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metadata)) return;

        $stmt = $this->app->db()->prepare(
            "UPDATE DriveActivityEvents
             SET Status = 'error', MetadataJson = ?
             WHERE user_id_ = ? AND Action = 'polly' AND Service = 'Polly' AND CorrelationId = ?"
        );
        if ($stmt) {
            $stmt->execute([$metadata, $userId, $correlation]);
            $stmt->close();
        }
    }

    private function recordFinalizationCost(int $userId, array $result, float $started): void
    {
        if (($result['finalized_now'] ?? false) !== true) {
            return;
        }

        $taskId = (string)($result['task_id'] ?? '');
        $units = [
            's3.put_request' => max(0, (int)($result['s3_put_requests'] ?? 0)),
            's3.copy_request' => max(0, (int)($result['s3_copy_requests'] ?? 0)),
            's3.delete_request' => max(0, (int)($result['s3_delete_requests'] ?? 0)),
            's3.head_request' => max(0, (int)($result['s3_head_requests'] ?? 0)),
            's3.storage_bytes_delta' => max(0, (int)($result['output_bytes'] ?? 0)),
        ];

        ActivityCostRecorder::fromDatabase($this->app->db())->success(
            $userId,
            'polly',
            'S3',
            ((int)($result['source_file_id'] ?? 0)) > 0 ? (int)$result['source_file_id'] : null,
            $units,
            $started,
            [
                'phase' => 'generated_output',
                'task_id' => $taskId,
                'engine' => (string)($result['engine_used'] ?? ''),
                'output_bytes' => max(0, (int)($result['output_bytes'] ?? 0)),
            ],
            ActivityCostRecorder::correlation('polly', $taskId)
        );
    }
}
