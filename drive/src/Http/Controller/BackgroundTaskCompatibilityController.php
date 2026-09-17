<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Activity\PollyTaskReconciler;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\PollyFileService;
use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;

/**
 * Compatibilidad y acciones directas para jobs Polly del centro Tareas.
 *
 * - Los jobs históricos sin task_id pueden cancelarse dentro de Drive para no
 *   quedar eternamente pendientes.
 * - "Revisar ahora" ejecuta una reconciliación real en la misma petición y
 *   devuelve el estado observado, en vez de limitarse a lanzar un worker sin
 *   informar al usuario qué ocurrió.
 */
final class BackgroundTaskCompatibilityController extends AbstractJsonController
{
    public function dispatchIfNeeded(): void
    {
        if ($this->request->method() !== 'POST') {
            return;
        }

        $action = strtolower($this->request->postString('task_action'));
        if (!in_array($action, ['cancel', 'reconcile'], true)) {
            return;
        }

        $controlId = trim($this->request->postString('task_id'));
        if (!str_starts_with($controlId, 'activity:')) {
            return;
        }

        $eventId = (int)substr($controlId, 9);
        if ($eventId <= 0) {
            return;
        }

        $userId = $this->guardAuthenticated();
        $row = $this->activityEvent($eventId, $userId);
        if (!is_array($row)) {
            return;
        }

        if (
            strtolower((string)($row['Action'] ?? '')) !== 'polly'
            || strtolower((string)($row['Service'] ?? '')) !== 'polly'
        ) {
            return;
        }

        $meta = $this->decodeMetadata((string)($row['MetadataJson'] ?? ''));

        if ($action === 'reconcile') {
            $this->reviewPollyNow($eventId, $userId, $row, $meta);
        }

        // Los jobs actuales sí tienen task_id y deben seguir la ruta normal de
        // cancelación, que además puede limpiar su salida temporal en S3.
        if (trim((string)($meta['task_id'] ?? '')) !== '') {
            return;
        }

        $phase = strtolower((string)($meta['phase'] ?? ''));
        $rawStatus = strtolower((string)($meta['task_status'] ?? $meta['status'] ?? ''));
        if (
            strtolower((string)($row['Status'] ?? '')) === 'error'
            || in_array($phase, ['completed', 'failed', 'cancelled'], true)
            || in_array($rawStatus, ['completed', 'failed', 'cancelled'], true)
        ) {
            return;
        }

        $now = gmdate('c');
        $meta = array_merge($meta, [
            'phase' => 'cancelled',
            'task_status' => 'cancelled',
            'cancelled_at' => $now,
            'task_center_updated_at' => $now,
            'cleanup_pending' => false,
            'cleanup_done' => false,
            'cleanup_skipped_reason' => 'legacy_missing_task_id',
        ]);

        $this->saveMetadata($eventId, $userId, $meta);

        JsonResponse::send([
            'ok' => true,
            'message' => 'Tarea antigua cancelada. Ya puedes eliminarla de Tareas; su historial de costos se conserva.',
            'status' => 'cancelled',
        ]);
    }

    private function reviewPollyNow(int $eventId, int $userId, array $row, array $meta): never
    {
        $taskId = trim((string)($meta['task_id'] ?? ''));
        if ($taskId === '') {
            JsonResponse::send([
                'ok' => true,
                'message' => 'Esta tarea Polly es histórica y no conserva el task_id de Amazon. No se puede consultar al proveedor; puedes cancelarla y eliminarla de Tareas.',
                'status' => $this->normalizedStatus($row, $meta),
            ]);
        }

        try {
            // run() es idempotente y usa la misma fuente persistente que el
            // timer. Ejecutarlo aquí hace que "Revisar ahora" consulte a Polly
            // de verdad y, si ya terminó, copie el temporal a su destino final
            // y actualice FileS3 antes de responder.
            $stats = $this->pollyReconciler()->run(1000);
        } catch (\Throwable $e) {
            JsonResponse::send([
                'ok' => false,
                'error' => 'No se pudo revisar Polly: ' . $e->getMessage(),
            ], 400);
        }

        $latest = $this->activityEvent($eventId, $userId) ?? $row;
        $latestMeta = $this->decodeMetadata((string)($latest['MetadataJson'] ?? ''));
        $status = $this->normalizedStatus($latest, $latestMeta);
        $message = $this->reviewMessage($status, $latestMeta, $stats);

        JsonResponse::send([
            'ok' => true,
            'message' => $message,
            'status' => $status,
            'review' => [
                'pending' => (int)($stats['pending'] ?? 0),
                'completed' => (int)($stats['completed'] ?? 0),
                'failed' => (int)($stats['failed'] ?? 0),
                'in_progress' => (int)($stats['in_progress'] ?? 0),
                'errors' => is_array($stats['errors'] ?? null) ? count($stats['errors']) : 0,
            ],
        ]);
    }

    private function reviewMessage(string $status, array $meta, array $stats): string
    {
        $errors = is_array($stats['errors'] ?? null) ? $stats['errors'] : [];
        if ($errors !== []) {
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            $detail = trim((string)($first['message'] ?? ''));
            return $detail !== ''
                ? 'Polly fue consultado, pero el reconciliador reportó: ' . $detail
                : 'Polly fue consultado, pero la reconciliación reportó un error.';
        }

        return match ($status) {
            'completed' => 'Amazon Polly terminó. Drive finalizó el audio y lo registró en la misma carpeta del texto.',
            'failed' => 'Amazon Polly reportó un fallo: ' . trim((string)($meta['reason'] ?? 'revisa el detalle de la tarea.')),
            'running' => 'Amazon Polly confirmó que el audio sigue procesándose. El servidor continuará revisándolo automáticamente.',
            'cancelled' => 'La tarea está cancelada.',
            default => 'Amazon Polly confirmó que el audio sigue en cola. El servidor continuará revisándolo automáticamente.',
        };
    }

    private function normalizedStatus(array $row, array $meta): string
    {
        $rowStatus = strtolower((string)($row['Status'] ?? ''));
        $phase = strtolower((string)($meta['phase'] ?? ''));
        $raw = strtolower((string)($meta['task_status'] ?? $meta['status'] ?? ''));

        if ($phase === 'cancelled' || $raw === 'cancelled') return 'cancelled';
        if ($rowStatus === 'error' || $phase === 'failed' || in_array($raw, ['failed', 'error'], true)) return 'failed';
        if ($phase === 'completed' || in_array($raw, ['completed', 'complete'], true)) return 'completed';
        if ($phase === 'running' || in_array($raw, ['inprogress', 'in_progress', 'running', 'processing'], true)) return 'running';
        return 'queued';
    }

    private function activityEvent(int $eventId, int $userId): ?array
    {
        $stmt = $this->app->db()->prepare(
            "SELECT id_, Action, Service, Status, MetadataJson
             FROM DriveActivityEvents
             WHERE id_ = ? AND user_id_ = ?
             LIMIT 1"
        );
        if (!$stmt || !$stmt->execute([$eventId, $userId])) {
            $error = $stmt ? $stmt->error : $this->app->db()->error;
            if ($stmt) {
                $stmt->close();
            }
            throw new RuntimeException('No se pudo consultar la tarea Polly: ' . $error);
        }

        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function saveMetadata(int $eventId, int $userId, array $meta): void
    {
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo serializar la cancelación histórica.');
        }

        $update = $this->app->db()->prepare(
            "UPDATE DriveActivityEvents
             SET MetadataJson = ?
             WHERE id_ = ? AND user_id_ = ?"
        );
        if (!$update || !$update->execute([$json, $eventId, $userId])) {
            $error = $update ? $update->error : $this->app->db()->error;
            if ($update) {
                $update->close();
            }
            throw new RuntimeException('No se pudo actualizar la tarea histórica: ' . $error);
        }
        $update->close();
    }

    private function pollyReconciler(): PollyTaskReconciler
    {
        $db = $this->app->db();
        $files = new PollyFileService(
            new FileRecordLocator($db),
            new GeneratedFileRepository($db),
            $this->app->s3(),
            \Config::getPolly(),
            $this->app->bucket()
        );

        return new PollyTaskReconciler(
            $db,
            $files,
            ActivityCostRecorder::fromDatabase($db)
        );
    }

    private function decodeMetadata(string $json): array
    {
        if ($json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
