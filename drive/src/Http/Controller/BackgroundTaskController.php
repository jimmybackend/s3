<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Sync\SyncJobStore;
use RuntimeException;

/**
 * Vista unificada de trabajos de larga duración del Drive.
 *
 * No crea una nueva fuente de verdad: normaliza los stores/telemetría que ya
 * usan Sync, Move, Transcribe y Polly. De este modo el UI no queda ligado a
 * AWS y futuros proveedores pueden sumarse devolviendo el mismo contrato.
 */
final class BackgroundTaskController extends AbstractJsonController
{
    private const TERMINAL_HISTORY_SECONDS = 86400;

    public function index(): never
    {
        try {
            $userId = $this->guardAuthenticated();
            $costs = $this->costsByCorrelation($userId);
            $tasks = [];
            $sourceErrors = [];

            try {
                $tasks = array_merge($tasks, $this->activityTasks($userId, $costs));
            } catch (\Throwable $e) {
                $sourceErrors['activity'] = $e->getMessage();
            }

            try {
                $tasks = array_merge($tasks, $this->syncTasks($userId));
            } catch (\Throwable $e) {
                $sourceErrors['sync'] = $e->getMessage();
            }

            try {
                $tasks = array_merge($tasks, $this->moveTasks($userId, $costs));
            } catch (\Throwable $e) {
                $sourceErrors['move'] = $e->getMessage();
            }

            $tasks = array_values(array_filter($tasks, fn(array $task): bool => $this->shouldExpose($task)));
            usort($tasks, static function (array $a, array $b): int {
                return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
            });

            $summary = [
                'active' => 0,
                'queued' => 0,
                'running' => 0,
                'failed' => 0,
                'completed_recent' => 0,
            ];

            foreach ($tasks as $task) {
                $status = (string)($task['status'] ?? 'pending');
                if (in_array($status, ['queued', 'running', 'pending'], true)) {
                    $summary['active']++;
                }
                if ($status === 'queued') $summary['queued']++;
                if ($status === 'running') $summary['running']++;
                if ($status === 'failed') $summary['failed']++;
                if ($status === 'completed') $summary['completed_recent']++;
            }

            JsonResponse::send([
                'ok' => true,
                'summary' => $summary,
                'tasks' => array_slice($tasks, 0, 100),
                'source_errors' => $sourceErrors,
                'generated_at' => gmdate('c'),
            ]);
        } catch (\Throwable $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function activityTasks(int $userId, array $costs): array
    {
        $stmt = $this->app->db()->prepare(
            "SELECT e.id_, e.Action, e.Service, e.FileId, e.CorrelationId,
                    e.EstimatedCost, e.Currency, e.PricingState, e.Status,
                    e.MetadataJson, e.CreatedAt, f.Nombre AS file_name
             FROM DriveActivityEvents e
             LEFT JOIN FileS3 f
               ON f.id_ = e.FileId AND f.user_id_ = e.user_id_
             WHERE e.user_id_ = ?
               AND e.CreatedAt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
               AND (
                    (e.Action = 'polly' AND e.Service = 'Polly' AND e.CorrelationId LIKE 'polly:%')
                 OR (e.Action = 'transcribe' AND e.Service = 'Transcribe' AND e.CorrelationId LIKE 'transcribe:%')
               )
             ORDER BY e.CreatedAt DESC, e.id_ DESC
             LIMIT 100"
        );
        if (!$stmt || !$stmt->execute([$userId])) {
            $error = $stmt ? $stmt->error : $this->app->db()->error;
            if ($stmt) $stmt->close();
            throw new RuntimeException('No se pudieron consultar tareas de IA: ' . $error);
        }

        $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
        $tasks = [];

        foreach ($rows as $row) {
            $meta = json_decode((string)($row['MetadataJson'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $action = strtolower((string)($row['Action'] ?? ''));
            $kind = $action === 'polly' ? 'polly' : 'transcribe';
            $correlation = (string)($row['CorrelationId'] ?? '');
            $cost = is_array($costs[$correlation] ?? null) ? $costs[$correlation] : [];
            $status = $this->activityStatus($row, $meta);
            $title = trim((string)($meta['output_name'] ?? $row['file_name'] ?? ''));
            if ($title === '') {
                $title = $kind === 'polly' ? 'Audio desde texto' : 'Transcripción';
            }

            $taskId = trim((string)($meta['task_id'] ?? $meta['job_name'] ?? ''));
            if ($taskId === '') {
                $taskId = $correlation !== '' ? $correlation : $kind . ':' . (int)$row['id_'];
            }

            $detail = $kind === 'polly'
                ? $this->pollyDetail($status, $meta)
                : $this->transcribeDetail($status, $meta);

            $tasks[] = [
                'id' => $kind . ':' . $taskId,
                'kind' => $kind,
                'category' => $kind === 'polly' ? 'Texto a audio' : 'Transcripción',
                'service' => $kind === 'polly' ? 'Polly' : 'Transcribe',
                'provider' => 'Amazon',
                'title' => $title,
                'status' => $status,
                'progress' => $status === 'completed' ? 100 : null,
                'progress_mode' => in_array($status, ['running', 'queued', 'pending'], true) ? 'indeterminate' : 'determinate',
                'detail' => $detail,
                'created_at' => (string)($row['CreatedAt'] ?? ''),
                'updated_at' => (string)($row['CreatedAt'] ?? ''),
                'estimated_cost' => array_key_exists('amount', $cost) ? $cost['amount'] : ($row['EstimatedCost'] !== null ? (float)$row['EstimatedCost'] : null),
                'currency' => (string)($cost['currency'] ?? $row['Currency'] ?? 'USD'),
                'pricing_state' => (string)($cost['pricing_state'] ?? $row['PricingState'] ?? ''),
                'metadata' => [
                    'engine' => (string)($meta['engine'] ?? ''),
                    'characters' => max(0, (int)($meta['characters'] ?? 0)),
                    'billable_seconds' => max(0, (int)($meta['billable_seconds_reference'] ?? 0)),
                ],
            ];
        }

        return $tasks;
    }

    private function syncTasks(int $userId): array
    {
        $tasks = [];
        foreach ((new SyncJobStore())->recentForUser($userId, 40) as $job) {
            $state = strtolower((string)($job['state'] ?? 'queued'));
            $status = match ($state) {
                'done' => 'completed',
                'error', 'failed' => 'failed',
                'running' => 'running',
                'queued' => 'queued',
                default => 'pending',
            };
            $scope = (string)($job['scope_prefix'] ?? '');
            $detail = trim((string)($job['message'] ?? ''));
            if ($detail === '') {
                $detail = $status === 'completed' ? 'Sincronización terminada.' : 'Sincronizando datos.';
            }

            $tasks[] = [
                'id' => 'sync:' . (string)($job['job_id'] ?? ''),
                'kind' => 'sync',
                'category' => 'Sincronización de datos',
                'service' => 'Drive Sync',
                'provider' => 'Drive',
                'title' => $scope !== '' ? 'Sincronizar ' . $this->shortPath($scope) : 'Sincronización de datos',
                'status' => $status,
                'progress' => $status === 'completed' ? 100 : null,
                'progress_mode' => in_array($status, ['running', 'queued', 'pending'], true) ? 'indeterminate' : 'determinate',
                'detail' => $detail,
                'created_at' => (string)($job['created_at'] ?? ''),
                'updated_at' => (string)($job['updated_at'] ?? $job['created_at'] ?? ''),
                'estimated_cost' => null,
                'currency' => 'USD',
                'pricing_state' => 'unpriced',
                'metadata' => [
                    'batch' => max(0, (int)($job['batch'] ?? 0)),
                    'files' => max(0, (int)($job['files'] ?? 0)),
                    'folders' => max(0, (int)($job['folders'] ?? 0)),
                ],
            ];
        }
        return $tasks;
    }

    private function moveTasks(int $userId, array $costs): array
    {
        $tasks = [];
        foreach ($this->app->moveJobStore()->recentForUser($userId, 40) as $job) {
            $rawStatus = strtolower((string)($job['status'] ?? 'queued'));
            $status = match ($rawStatus) {
                'completed' => 'completed',
                'failed', 'error' => 'failed',
                'running' => 'running',
                'queued' => 'queued',
                default => 'pending',
            };
            $jobId = (string)($job['id'] ?? '');
            $type = (string)($job['type'] ?? 'files');
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $correlation = ActivityCostRecorder::correlation('move-job', $jobId) ?? '';
            $cost = is_array($costs[$correlation] ?? null) ? $costs[$correlation] : [];
            $destination = trim((string)($payload['destination'] ?? ''));
            $count = is_array($payload['refs'] ?? null) ? count($payload['refs']) : 0;

            $tasks[] = [
                'id' => 'move:' . $jobId,
                'kind' => 'move',
                'category' => $type === 'folder' ? 'Traslado de carpeta' : 'Traslado de archivos',
                'service' => 'Drive Move',
                'provider' => 'Drive',
                'title' => $type === 'folder'
                    ? 'Mover ' . $this->shortPath((string)($payload['origin'] ?? 'carpeta'))
                    : ($count > 0 ? 'Mover ' . $count . ' archivo(s)' : 'Mover archivos'),
                'status' => $status,
                'progress' => $status === 'completed' ? 100 : null,
                'progress_mode' => in_array($status, ['running', 'queued', 'pending'], true) ? 'indeterminate' : 'determinate',
                'detail' => (string)($job['message'] ?? ($destination !== '' ? 'Destino: ' . $this->shortPath($destination) : 'Movimiento en segundo plano.')),
                'created_at' => (string)($job['created_at'] ?? ''),
                'updated_at' => (string)($job['updated_at'] ?? $job['created_at'] ?? ''),
                'estimated_cost' => $cost['amount'] ?? null,
                'currency' => (string)($cost['currency'] ?? 'USD'),
                'pricing_state' => (string)($cost['pricing_state'] ?? ($status === 'completed' ? 'unpriced' : 'pending')),
                'metadata' => [
                    'items' => $count,
                    'destination' => $this->shortPath($destination),
                ],
            ];
        }
        return $tasks;
    }

    private function costsByCorrelation(int $userId): array
    {
        $stmt = $this->app->db()->prepare(
            "SELECT CorrelationId,
                    SUM(COALESCE(EstimatedCost, 0)) AS amount,
                    MAX(Currency) AS currency,
                    SUM(CASE WHEN PricingState = 'unpriced' THEN 1 ELSE 0 END) AS unpriced_count,
                    SUM(CASE WHEN PricingState = 'partial' THEN 1 ELSE 0 END) AS partial_count
             FROM DriveActivityEvents
             WHERE user_id_ = ?
               AND CorrelationId IS NOT NULL
               AND CreatedAt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
             GROUP BY CorrelationId"
        );
        if (!$stmt || !$stmt->execute([$userId])) {
            if ($stmt) $stmt->close();
            return [];
        }
        $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
        $map = [];
        foreach ($rows as $row) {
            $correlation = (string)($row['CorrelationId'] ?? '');
            if ($correlation === '') continue;
            $unpriced = (int)($row['unpriced_count'] ?? 0);
            $partial = (int)($row['partial_count'] ?? 0);
            $map[$correlation] = [
                'amount' => (float)($row['amount'] ?? 0),
                'currency' => (string)($row['currency'] ?? 'USD'),
                'pricing_state' => $unpriced > 0 ? ($partial > 0 ? 'partial' : 'unpriced') : ($partial > 0 ? 'partial' : 'complete'),
            ];
        }
        return $map;
    }

    private function activityStatus(array $row, array $meta): string
    {
        $rowStatus = strtolower((string)($row['Status'] ?? ''));
        $phase = strtolower((string)($meta['phase'] ?? ''));
        $raw = strtolower((string)($meta['task_status'] ?? $meta['status'] ?? ''));

        if ($rowStatus === 'error' || $phase === 'failed' || in_array($raw, ['failed', 'error'], true)) return 'failed';
        if ($phase === 'completed' || in_array($raw, ['completed', 'complete'], true)) return 'completed';
        if (in_array($raw, ['in_progress', 'running', 'processing'], true) || $phase === 'running') return 'running';
        if (in_array($raw, ['queued', 'scheduled'], true) || $phase === 'started') return 'queued';
        return 'pending';
    }

    private function pollyDetail(string $status, array $meta): string
    {
        $engine = trim((string)($meta['engine'] ?? ''));
        return match ($status) {
            'completed' => 'Audio generado' . ($engine !== '' ? ' · motor ' . $engine : '') . '.',
            'failed' => trim((string)($meta['reason'] ?? 'La generación de audio falló.')),
            'running' => 'Amazon Polly está generando el audio.',
            default => 'Audio en cola para generación.',
        };
    }

    private function transcribeDetail(string $status, array $meta): string
    {
        $seconds = max(0, (int)($meta['billable_seconds_reference'] ?? 0));
        return match ($status) {
            'completed' => $seconds > 0 ? 'Transcripción terminada · ' . $seconds . ' s facturables.' : 'Transcripción terminada.',
            'failed' => 'La transcripción terminó con error.',
            'running' => 'Amazon Transcribe está procesando el archivo.',
            default => 'Transcripción en cola.',
        };
    }

    private function shouldExpose(array $task): bool
    {
        $status = (string)($task['status'] ?? 'pending');
        if (in_array($status, ['queued', 'running', 'pending'], true)) {
            return true;
        }

        $updated = strtotime((string)($task['updated_at'] ?? ''));
        if ($updated === false) {
            return false;
        }
        return $updated >= time() - self::TERMINAL_HISTORY_SECONDS;
    }

    private function shortPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') return '';
        $parts = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
        return (string)($parts[count($parts) - 1] ?? $path);
    }
}
