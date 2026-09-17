<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;

/**
 * Compatibilidad para jobs históricos creados antes de que Polly persistiera
 * task_id en MetadataJson.
 *
 * Esos registros no se pueden asociar con seguridad a una tarea concreta de
 * Amazon, pero tampoco deben quedar eternamente bloqueados como pendientes.
 * Al cancelarlos se cierra únicamente su ciclo dentro de ArcadeCloud Drive;
 * el historial/costo en DriveActivityEvents permanece intacto.
 */
final class BackgroundTaskCompatibilityController extends AbstractJsonController
{
    public function dispatchIfNeeded(): void
    {
        if ($this->request->method() !== 'POST') {
            return;
        }

        if (strtolower($this->request->postString('task_action')) !== 'cancel') {
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
            throw new RuntimeException('No se pudo consultar la tarea histórica: ' . $error);
        }

        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return;
        }

        if (
            strtolower((string)($row['Action'] ?? '')) !== 'polly'
            || strtolower((string)($row['Service'] ?? '')) !== 'polly'
        ) {
            return;
        }

        $meta = json_decode((string)($row['MetadataJson'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];

        // Los jobs actuales sí tienen task_id y deben seguir la ruta normal,
        // que además puede limpiar su salida temporal cuando Amazon termine.
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
            throw new RuntimeException('No se pudo cancelar la tarea histórica: ' . $error);
        }
        $update->close();

        JsonResponse::send([
            'ok' => true,
            'message' => 'Tarea antigua cancelada. Ya puedes eliminarla de Tareas; su historial de costos se conserva.',
        ]);
    }
}
