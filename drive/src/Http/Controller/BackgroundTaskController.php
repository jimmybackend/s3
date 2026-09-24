<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Application\BackgroundWorkerLauncher;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use ArcadeCloud\Drive\Sync\SyncJobStore;
use RuntimeException;

/**
 * Centro unificado de trabajos de larga duración del Drive.
 *
 * La interfaz es neutral respecto del proveedor y las acciones se ejecutan
 * contra las fuentes persistentes reales: SyncJobStore, MoveJobStore y
 * DriveActivityEvents. Así el estado y los controles sobreviven a cambios de
 * página, cierres del navegador y nuevas sesiones.
 */
final class BackgroundTaskController extends AbstractJsonController
{
    private const TERMINAL_HISTORY_SECONDS = 86400;
    private const TRANSCRIBE_SCAN_LIMIT = 2000;

    public function dispatch(): never
    {
        if ($this->request->method() === 'POST') {
            $this->action();
        }
        $this->index();
    }

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

            try {
                $tasks = array_merge($tasks, $this->mediaTasks($userId));
            } catch (\Throwable $e) {
                $sourceErrors['media'] = $e->getMessage();
            }

            $tasks = array_values(array_filter($tasks, fn(array $task): bool => $this->shouldExpose($task)));
            usort($tasks, function (array $a, array $b): int {
                $rankA = $this->statusRank((string)($a['status'] ?? 'pending'));
                $rankB = $this->statusRank((string)($b['status'] ?? 'pending'));
                if ($rankA !== $rankB) return $rankA <=> $rankB;
                return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
            });

            $summary = [
                'active' => 0,
                'queued' => 0,
                'running' => 0,
                'stopping' => 0,
                'failed' => 0,
                'completed_recent' => 0,
                'cancelled_recent' => 0,
            ];

            foreach ($tasks as $task) {
                $status = (string)($task['status'] ?? 'pending');
                if (in_array($status, ['queued', 'running', 'pending', 'stopping'], true)) {
                    $summary['active']++;
                }
                if ($status === 'queued' || $status === 'pending') $summary['queued']++;
                if ($status === 'running') $summary['running']++;
                if ($status === 'stopping') $summary['stopping']++;
                if ($status === 'failed') $summary['failed']++;
                if ($status === 'completed') $summary['completed_recent']++;
                if ($status === 'cancelled') $summary['cancelled_recent']++;
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

    public function action(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $controlId = $this->requireNonEmpty(
                $this->request->postString('task_id'),
                'Falta el identificador de la tarea.'
            );
            $action = strtolower($this->requireNonEmpty(
                $this->request->postString('task_action'),
                'Falta la acción de la tarea.'
            ));

            if (str_starts_with($controlId, 'sync:')) {
                $message = $this->handleSyncAction($userId, substr($controlId, 5), $action);
            } elseif (str_starts_with($controlId, 'move:')) {
                $message = $this->handleMoveAction($userId, substr($controlId, 5), $action);
            } elseif (str_starts_with($controlId, 'activity:')) {
                $message = $this->handleActivityAction($userId, (int)substr($controlId, 9), $action);
            } else {
                throw new RuntimeException('Tipo de tarea no reconocido.');
            }

            JsonResponse::send([
                'ok' => true,
                'message' => $message,
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
            $meta = $this->decodeMetadata((string)($row['MetadataJson'] ?? ''));
            if (!empty($meta['task_center_hidden_at'])) {
                continue;
            }

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

            $terminal = in_array($status, ['completed', 'failed', 'cancelled'], true);
            $actions = [];
            if ($terminal) {
                $actions[] = $this->uiAction('dismiss', 'Eliminar de Tareas', 'muted', true);
            } else {
                $actions[] = $this->uiAction('reconcile', 'Revisar ahora', 'primary', false);
                $actions[] = $this->uiAction('cancel', 'Cancelar', 'danger', true);
            }

            $tasks[] = [
                'id' => $kind . ':' . $taskId,
                'control_id' => 'activity:' . (int)$row['id_'],
                'kind' => $kind,
                'category' => $kind === 'polly' ? 'Texto a audio' : 'Transcripción',
                'service' => $kind === 'polly' ? 'Polly' : 'Transcribe',
                'provider' => 'Amazon',
                'title' => $title,
                'status' => $status,
                'progress' => $status === 'completed' ? 100 : null,
                'progress_mode' => in_array($status, ['running', 'queued', 'pending', 'stopping'], true) ? 'indeterminate' : 'determinate',
                'detail' => $kind === 'polly'
                    ? $this->pollyDetail($status, $meta)
                    : $this->transcribeDetail($status, $meta),
                'created_at' => (string)($row['CreatedAt'] ?? ''),
                'updated_at' => (string)($meta['task_center_updated_at'] ?? $row['CreatedAt'] ?? ''),
                'estimated_cost' => array_key_exists('amount', $cost)
                    ? $cost['amount']
                    : ($row['EstimatedCost'] !== null ? (float)$row['EstimatedCost'] : null),
                'currency' => (string)($cost['currency'] ?? $row['Currency'] ?? 'USD'),
                'pricing_state' => (string)($cost['pricing_state'] ?? $row['PricingState'] ?? ''),
                'actions' => $actions,
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
                'cancel_requested' => 'stopping',
                'cancelled' => 'cancelled',
                'queued' => 'queued',
                default => 'pending',
            };
            $scope = (string)($job['scope_prefix'] ?? '');
            $detail = trim((string)($job['message'] ?? ''));
            if ($detail === '') {
                $detail = $status === 'completed' ? 'Sincronización terminada.' : 'Sincronizando datos.';
            }

            $actions = [];
            if (in_array($status, ['queued', 'pending', 'failed', 'cancelled'], true)) {
                $actions[] = $this->uiAction('run_now', 'Iniciar ahora', 'primary', false);
            }
            if (in_array($status, ['queued', 'pending'], true)) {
                $actions[] = $this->uiAction('cancel', 'Cancelar', 'danger', true);
                $actions[] = $this->uiAction('delete', 'Eliminar de Tareas', 'muted', true);
            } elseif ($status === 'running') {
                $actions[] = $this->uiAction('cancel', 'Detener', 'danger', true);
            }
            if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
                $actions[] = $this->uiAction('delete', 'Eliminar de Tareas', 'muted', true);
            }

            $jobId = (string)($job['job_id'] ?? '');
            $tasks[] = [
                'id' => 'sync:' . $jobId,
                'control_id' => 'sync:' . $jobId,
                'kind' => 'sync',
                'category' => 'Sincronización de datos',
                'service' => 'Drive Sync',
                'provider' => 'Drive',
                'title' => $scope !== '' ? 'Sincronizar ' . $this->shortPath($scope) : 'Sincronización de datos',
                'status' => $status,
                'progress' => $status === 'completed' ? 100 : null,
                'progress_mode' => in_array($status, ['running', 'queued', 'pending', 'stopping'], true) ? 'indeterminate' : 'determinate',
                'detail' => $detail,
                'created_at' => (string)($job['created_at'] ?? ''),
                'updated_at' => (string)($job['updated_at'] ?? $job['created_at'] ?? ''),
                'estimated_cost' => null,
                'currency' => 'USD',
                'pricing_state' => 'unpriced',
                'actions' => $actions,
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
                'cancel_requested' => 'stopping',
                'cancelled' => 'cancelled',
                'queued' => 'queued',
                default => 'pending',
            };
            $jobId = (string)($job['id'] ?? '');
            $type = (string)($job['type'] ?? 'files');
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $result = is_array($job['result'] ?? null) ? $job['result'] : [];
            $correlation = ActivityCostRecorder::correlation('move-job', $jobId) ?? '';
            $cost = is_array($costs[$correlation] ?? null) ? $costs[$correlation] : [];
            $destination = trim((string)($payload['destination'] ?? ''));
            $count = is_array($payload['refs'] ?? null) ? count($payload['refs']) : 0;
            $processed = max(0, (int)($result['total'] ?? 0));

            $actions = [];
            if ($status === 'queued' || ($status === 'cancelled' && $processed === 0)) {
                $actions[] = $this->uiAction('run_now', 'Iniciar ahora', 'primary', false);
            }
            if (in_array($status, ['queued', 'pending'], true)) {
                $actions[] = $this->uiAction('cancel', 'Cancelar', 'danger', true);
                $actions[] = $this->uiAction('delete', 'Eliminar de Tareas', 'muted', true);
            } elseif ($status === 'running' && $type === 'files') {
                $actions[] = $this->uiAction('cancel', 'Detener', 'danger', true);
            }
            if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
                $actions[] = $this->uiAction('delete', 'Eliminar de Tareas', 'muted', true);
            }

            $tasks[] = [
                'id' => 'move:' . $jobId,
                'control_id' => 'move:' . $jobId,
                'kind' => 'move',
                'category' => $type === 'folder' ? 'Traslado de carpeta' : 'Traslado de archivos',
                'service' => 'Drive Move',
                'provider' => 'Drive',
                'title' => $type === 'folder'
                    ? 'Mover ' . $this->shortPath((string)($payload['origin'] ?? 'carpeta'))
                    : ($count > 0 ? 'Mover ' . $count . ' archivo(s)' : 'Mover archivos'),
                'status' => $status,
                'progress' => $status === 'completed' ? 100 : null,
                'progress_mode' => in_array($status, ['running', 'queued', 'pending', 'stopping'], true) ? 'indeterminate' : 'determinate',
                'detail' => (string)($job['message'] ?? ($destination !== '' ? 'Destino: ' . $this->shortPath($destination) : 'Movimiento en segundo plano.')),
                'created_at' => (string)($job['created_at'] ?? ''),
                'updated_at' => (string)($job['updated_at'] ?? $job['created_at'] ?? ''),
                'estimated_cost' => $cost['amount'] ?? null,
                'currency' => (string)($cost['currency'] ?? 'USD'),
                'pricing_state' => (string)($cost['pricing_state'] ?? ($status === 'completed' ? 'unpriced' : 'pending')),
                'actions' => $actions,
                'metadata' => [
                    'items' => $count,
                    'processed_items' => $processed,
                    'destination' => $this->shortPath($destination),
                    'type' => $type,
                ],
            ];
        }
        return $tasks;
    }

    private function mediaTasks(int $userId): array
    {
        $tasks = [];
        $repo = new MediaProcessingJobRepository($this->app->db());

        foreach ($repo->recentForUser($userId, 40) as $job) {
            $status = strtolower((string)($job['status'] ?? 'queued'));
            if (!in_array($status, ['queued','running','completed','failed','cancelled'], true)) {
                $status = 'pending';
            }

            $operation = (string)($job['operation'] ?? '');
            $category = match ($operation) {
                'split_video' => 'División de video',
                'split_audio' => 'División de audio',
                'extract_mp3' => 'Extracción de MP3',
                default => 'Procesamiento multimedia',
            };
            $detail = match ($status) {
                'queued' => 'En espera de un nodo multimedia disponible.',
                'running' => 'Procesando en el nodo multimedia.',
                'completed' => 'Archivos generados y guardados en la misma carpeta.',
                'failed' => (string)($job['error'] ?? 'El procesamiento multimedia falló.'),
                default => 'Procesamiento multimedia.',
            };

            $progress = max(0, min(100, (int)($job['progress'] ?? 0)));
            $tasks[] = [
                'id' => 'media:' . (string)($job['job_id'] ?? ''),
                'control_id' => 'media:' . (string)($job['job_id'] ?? ''),
                'kind' => 'media',
                'category' => $category,
                'service' => 'FFmpeg Worker',
                'provider' => 'ArcadeCloud',
                'title' => (string)($job['source_name'] ?? 'Archivo multimedia'),
                'status' => $status,
                'progress' => in_array($status, ['running','completed'], true) ? $progress : null,
                'progress_mode' => $status === 'running' ? 'determinate' : ($status === 'completed' ? 'determinate' : 'indeterminate'),
                'detail' => $detail,
                'created_at' => (string)($job['created_at'] ?? ''),
                'updated_at' => (string)($job['updated_at'] ?? $job['created_at'] ?? ''),
                'estimated_cost' => null,
                'currency' => 'USD',
                'pricing_state' => 'unpriced',
                'actions' => [],
                'metadata' => [
                    'parts' => (int)($job['parts'] ?? 0),
                    'overlap_before_seconds' => (int)($job['overlap_before'] ?? 0),
                    'overlap_after_seconds' => (int)($job['overlap_after'] ?? 0),
                    'outputs' => is_array($job['outputs'] ?? null) ? count($job['outputs']) : 0,
                ],
            ];
        }

        return $tasks;
    }

    private function handleSyncAction(int $userId, string $jobId, string $action): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new RuntimeException('Tarea de sincronización inválida.');
        }

        $store = new SyncJobStore();
        $job = $store->read($userId, $jobId);
        if (!is_array($job)) {
            throw new RuntimeException('Sincronización no encontrada.');
        }
        $state = strtolower((string)($job['state'] ?? 'queued'));

        if ($action === 'run_now') {
            if (!in_array($state, ['queued', 'error', 'failed', 'cancelled'], true)) {
                throw new RuntimeException('Esta sincronización no puede ejecutarse de nuevo en su estado actual.');
            }
            $scope = trim((string)($job['scope_prefix'] ?? ''));
            $store->update($userId, $jobId, [
                'state' => 'queued',
                'message' => 'En cola para ejecución inmediata.',
                'finished_at' => null,
            ]);
            $this->workerLauncher()->launchSync($userId, $jobId, $scope);
            return 'Sincronización enviada al worker del servidor.';
        }

        if ($action === 'cancel') {
            if (in_array($state, ['queued', 'error', 'failed'], true)) {
                $store->update($userId, $jobId, [
                    'state' => 'cancelled',
                    'message' => 'Sincronización cancelada.',
                    'finished_at' => date('c'),
                ]);
                return 'Sincronización cancelada.';
            }
            if ($state === 'running') {
                $store->update($userId, $jobId, [
                    'state' => 'cancel_requested',
                    'message' => 'Detención solicitada; se aplicará al terminar el lote actual.',
                ]);
                return 'Se solicitó detener la sincronización de forma segura.';
            }
            throw new RuntimeException('La sincronización ya no se puede detener en ese estado.');
        }

        if ($action === 'delete') {
            $store->deleteForUser($userId, $jobId);
            return 'Sincronización eliminada de Tareas.';
        }

        throw new RuntimeException('Acción de sincronización no permitida.');
    }

    private function handleMoveAction(int $userId, string $jobId, string $action): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new RuntimeException('Tarea de movimiento inválida.');
        }

        $store = $this->app->moveJobStore();
        $job = $store->getForUser($userId, $jobId);
        $status = strtolower((string)($job['status'] ?? 'queued'));
        $type = (string)($job['type'] ?? 'files');
        $result = is_array($job['result'] ?? null) ? $job['result'] : [];

        if ($action === 'run_now') {
            $processed = max(0, (int)($result['total'] ?? 0));
            if ($status !== 'queued' && !($status === 'cancelled' && $processed === 0)) {
                throw new RuntimeException('Este traslado no puede reiniciarse de forma segura.');
            }
            if ($status === 'cancelled') {
                $store->update($jobId, [
                    'status' => 'queued',
                    'message' => 'En cola para ejecución inmediata.',
                    'error' => null,
                    'result' => null,
                ]);
            }
            $this->workerLauncher()->launchMove($jobId);
            return 'Traslado enviado al worker del servidor.';
        }

        if ($action === 'cancel') {
            if (in_array($status, ['queued', 'pending'], true)) {
                $store->update($jobId, [
                    'status' => 'cancelled',
                    'message' => 'Traslado cancelado antes de iniciar.',
                    'error' => null,
                ]);
                return 'Traslado cancelado.';
            }
            if ($status === 'running' && $type === 'files') {
                $store->update($jobId, [
                    'status' => 'cancel_requested',
                    'message' => 'Detención solicitada; se aplicará después del archivo actual.',
                ]);
                return 'Se solicitó detener el traslado de forma segura.';
            }
            if ($status === 'running' && $type === 'folder') {
                throw new RuntimeException('Una carpeta ya en movimiento no se interrumpe a mitad para evitar dejar datos parcialmente trasladados.');
            }
            throw new RuntimeException('El traslado ya no se puede detener en ese estado.');
        }

        if ($action === 'delete') {
            $store->deleteForUser($userId, $jobId);
            return 'Traslado eliminado de Tareas.';
        }

        throw new RuntimeException('Acción de traslado no permitida.');
    }

    private function handleActivityAction(int $userId, int $eventId, string $action): string
    {
        if ($eventId <= 0) {
            throw new RuntimeException('Tarea de servicio inválida.');
        }

        $stmt = $this->app->db()->prepare(
            "SELECT id_, Action, Service, Status, CorrelationId, MetadataJson
             FROM DriveActivityEvents
             WHERE id_ = ? AND user_id_ = ?
             LIMIT 1"
        );
        if (!$stmt || !$stmt->execute([$eventId, $userId])) {
            $error = $stmt ? $stmt->error : $this->app->db()->error;
            if ($stmt) $stmt->close();
            throw new RuntimeException('No se pudo consultar la tarea: ' . $error);
        }
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new RuntimeException('Tarea no encontrada.');
        }

        $kind = strtolower((string)($row['Action'] ?? ''));
        if (!in_array($kind, ['polly', 'transcribe'], true)) {
            throw new RuntimeException('Esta tarea no admite controles desde el centro.');
        }

        $meta = $this->decodeMetadata((string)($row['MetadataJson'] ?? ''));
        $status = $this->activityStatus($row, $meta);

        if ($action === 'reconcile') {
            if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
                return 'La tarea ya está en estado terminal.';
            }
            $this->workerLauncher()->launchReconcile($kind);
            return 'El servidor revisará ahora el estado real del proveedor.';
        }

        if ($action === 'cancel') {
            if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
                return 'La tarea ya está en estado terminal.';
            }

            $now = gmdate('c');
            if ($kind === 'polly') {
                $taskId = trim((string)($meta['task_id'] ?? ''));
                if ($taskId === '') {
                    throw new RuntimeException('La tarea Polly no tiene task_id persistente y no puede cancelarse con seguridad.');
                }
                $meta = array_merge($meta, [
                    'phase' => 'cancelled',
                    'task_status' => 'cancelled',
                    'cancelled_at' => $now,
                    'cleanup_pending' => true,
                    'cleanup_done' => false,
                    'task_center_updated_at' => $now,
                ]);
                $this->saveActivityMetadata($eventId, $userId, $meta);
                $this->workerLauncher()->launchReconcile('polly');
                return 'Audio cancelado en Drive. El servidor limpiará la salida temporal de Polly cuando aparezca.';
            }

            $correlation = trim((string)($row['CorrelationId'] ?? ''));
            $jobName = trim((string)($meta['job_name'] ?? ''));
            if ($jobName === '' && $correlation !== '') {
                $jobName = $this->resolveTranscribeJobName($correlation);
            }
            if ($jobName === '') {
                throw new RuntimeException('No se pudo localizar el job de Amazon Transcribe para cancelarlo. Usa Revisar ahora y vuelve a intentar.');
            }

            \Config::getTranscribe()->deleteTranscriptionJob([
                'TranscriptionJobName' => $jobName,
            ]);
            $meta = array_merge($meta, [
                'phase' => 'cancelled',
                'status' => 'CANCELLED',
                'job_name' => $jobName,
                'cancelled_at' => $now,
                'task_center_updated_at' => $now,
            ]);
            $this->saveActivityMetadata($eventId, $userId, $meta);
            return 'Transcripción cancelada y eliminada del proveedor. Ya puedes eliminarla de Tareas.';
        }

        if ($action === 'dismiss') {
            if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
                throw new RuntimeException('Una tarea activa no se puede eliminar de la lista; primero cancélala o detenla.');
            }
            $meta['task_center_hidden_at'] = gmdate('c');
            $meta['task_center_updated_at'] = gmdate('c');
            $this->saveActivityMetadata($eventId, $userId, $meta);
            return 'Tarea eliminada del centro. El historial de costos se conserva.';
        }

        throw new RuntimeException('Acción de servicio no permitida.');
    }

    private function saveActivityMetadata(int $eventId, int $userId, array $meta): void
    {
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo guardar el estado de la tarea.');
        }
        $update = $this->app->db()->prepare(
            "UPDATE DriveActivityEvents SET MetadataJson = ? WHERE id_ = ? AND user_id_ = ?"
        );
        if (!$update || !$update->execute([$json, $eventId, $userId])) {
            $error = $update ? $update->error : $this->app->db()->error;
            if ($update) $update->close();
            throw new RuntimeException('No se pudo actualizar la tarea: ' . $error);
        }
        $update->close();
    }

    private function resolveTranscribeJobName(string $correlation): string
    {
        if ($correlation === '') return '';

        $client = \Config::getTranscribe();
        $scanned = 0;
        foreach (['QUEUED', 'IN_PROGRESS', 'COMPLETED', 'FAILED'] as $awsStatus) {
            $nextToken = null;
            do {
                $args = [
                    'Status' => $awsStatus,
                    'MaxResults' => 100,
                ];
                if (is_string($nextToken) && $nextToken !== '') {
                    $args['NextToken'] = $nextToken;
                }
                $page = $client->listTranscriptionJobs($args);
                foreach ((array)($page['TranscriptionJobSummaries'] ?? []) as $summary) {
                    if (!is_array($summary)) continue;
                    $jobName = trim((string)($summary['TranscriptionJobName'] ?? ''));
                    if ($jobName === '') continue;
                    $scanned++;
                    if (ActivityCostRecorder::correlation('transcribe', $jobName) === $correlation) {
                        return $jobName;
                    }
                    if ($scanned >= self::TRANSCRIBE_SCAN_LIMIT) {
                        return '';
                    }
                }
                $nextToken = isset($page['NextToken']) ? (string)$page['NextToken'] : null;
            } while ($nextToken !== null && $nextToken !== '');
        }

        return '';
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

        if ($phase === 'cancelled' || $raw === 'cancelled') return 'cancelled';
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
            'cancelled' => !empty($meta['cleanup_pending'])
                ? 'Cancelada. El servidor limpiará el temporal de Polly cuando esté disponible.'
                : 'Cancelada; no se guardará el audio generado.',
            'running' => 'El proveedor está generando el audio.',
            default => 'Audio en cola para generación.',
        };
    }

    private function transcribeDetail(string $status, array $meta): string
    {
        $seconds = max(0, (int)($meta['billable_seconds_reference'] ?? 0));
        return match ($status) {
            'completed' => $seconds > 0 ? 'Transcripción terminada · ' . $seconds . ' s facturables.' : 'Transcripción terminada.',
            'failed' => 'La transcripción terminó con error.',
            'cancelled' => 'Transcripción cancelada.',
            'running' => 'El proveedor está procesando el archivo.',
            default => 'Transcripción en cola.',
        };
    }

    private function shouldExpose(array $task): bool
    {
        $status = (string)($task['status'] ?? 'pending');
        if (in_array($status, ['queued', 'running', 'pending', 'stopping'], true)) {
            return true;
        }

        $updated = strtotime((string)($task['updated_at'] ?? ''));
        if ($updated === false) {
            return false;
        }
        return $updated >= time() - self::TERMINAL_HISTORY_SECONDS;
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            'running', 'stopping' => 0,
            'queued', 'pending' => 1,
            'failed' => 2,
            'completed' => 3,
            'cancelled' => 4,
            default => 5,
        };
    }

    private function uiAction(string $id, string $label, string $tone, bool $confirm): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'tone' => $tone,
            'confirm' => $confirm,
        ];
    }

    private function workerLauncher(): BackgroundWorkerLauncher
    {
        return new BackgroundWorkerLauncher(dirname(__DIR__, 3));
    }

    private function decodeMetadata(string $json): array
    {
        if ($json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function shortPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') return '';
        $parts = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
        return (string)($parts[count($parts) - 1] ?? $path);
    }
}
