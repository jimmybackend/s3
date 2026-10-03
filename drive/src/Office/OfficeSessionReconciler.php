<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use mysqli;
use RuntimeException;
use Throwable;

final class OfficeSessionReconciler
{
    private const PREPARING_STALE_SECONDS = 900;
    private const READY_STALE_SECONDS = 180;
    private const PROCESS_HEARTBEAT_SECONDS = 45;

    private string $workspaceRoot;

    public function __construct(private mysqli $db)
    {
        $this->workspaceRoot = rtrim(
            trim((string)(getenv('ARCADECLOUD_OFFICE_WORKSPACE_ROOT')
                ?: '/var/lib/arcadecloud-office/phase1-workspace')),
            '/'
        );
    }

    /** @return array{reconciled:int,reasons:array<int,string>} */
    public function reconcile(string $instanceId): array
    {
        if (!preg_match('/^i-[0-9a-f]{8,17}$/i', $instanceId)) {
            throw new RuntimeException('Instancia Office inválida para reconciliación.');
        }

        try {
            $leaseActive = $this->hasActiveLease($instanceId);
            $candidates = $this->activeCandidates($instanceId);
        } catch (Throwable $e) {
            error_log('[ArcadeCloud Office] reconciliación no disponible: ' . $e->getMessage());
            throw new RuntimeException(
                'No se pudo reconciliar Office; el apagado queda bloqueado.',
                0,
                $e
            );
        }

        $reconciled = 0;
        $reasons = [];

        foreach ($candidates as $session) {
            $sessionId = (string)($session['session_id'] ?? '');
            $status = (string)($session['status'] ?? '');
            $updatedAt = strtotime((string)($session['updated_at'] ?? '') . ' UTC');
            $age = $updatedAt === false ? 0 : max(0, time() - $updatedAt);

            if (
                $status === 'preparing'
                && trim((string)($session['workspace_relative'] ?? '')) === ''
                && $age >= self::PREPARING_STALE_SECONDS
                && !$leaseActive
                && is_dir($this->workspaceRoot . '/sessions')
                && preg_match('/^[a-f0-9]{32}$/', $sessionId)
                && !file_exists($this->workspaceRoot . '/sessions/' . $sessionId)
            ) {
                $this->markFailed($sessionId);
                $reconciled++;
                error_log('[ArcadeCloud Office] sesión preparing abandonada reconciliada: ' . $sessionId);
                continue;
            }

            if ($status !== 'ready') {
                $reasons[] = 'office_document_' . $status;
                continue;
            }
            if ($leaseActive) {
                $reasons[] = 'office_lease_active';
                continue;
            }
            if ($age < self::READY_STALE_SECONDS) {
                $reasons[] = 'office_document_recent';
                continue;
            }

            $relative = trim((string)($session['workspace_relative'] ?? ''));
            $path = $this->workspacePath($relative);
            if ($path === null || !is_file($path)) {
                $reasons[] = 'office_workspace_unavailable';
                continue;
            }

            $directory = dirname($path);
            $managed = $directory . '/.arcadecloud-office-managed';
            $active = $directory . '/.arcadecloud-office-active';

            // Las sesiones creadas antes del monitor de proceso no se cierran
            // automáticamente: no existe prueba suficiente de que LibreOffice
            // haya dejado de editar.
            if (!is_file($managed)) {
                $reasons[] = 'office_legacy_session';
                continue;
            }

            clearstatcache(true, $active);
            $activeMtime = is_file($active) ? (int)(@filemtime($active) ?: 0) : 0;
            if ($activeMtime > 0 && (time() - $activeMtime) <= self::PROCESS_HEARTBEAT_SECONDS) {
                $reasons[] = 'office_process_active';
                continue;
            }

            clearstatcache(true, $path);
            $mtime = (int)(@filemtime($path) ?: 0);
            $fileSize = @filesize($path);
            $size = $fileSize === false ? -1 : (int)$fileSize;
            $lastMtime = (int)($session['last_workspace_mtime'] ?? 0);
            $lastSize = (int)($session['last_workspace_size'] ?? -1);
            $lastSyncedAt = trim((string)($session['last_synced_at'] ?? ''));

            if (
                !$this->readyWorkspaceIsSynced([
                    'SessionId' => $sessionId, 'WorkspaceRelative' => $relative,
                    'LastSyncedAt' => $lastSyncedAt, 'LastWorkspaceMtime' => $lastMtime,
                    'LastWorkspaceSize' => $lastSize,
                ])
                || $lastSyncedAt === ''
                || $mtime <= 0
                || $size < 0
                || $mtime !== $lastMtime
                || $size !== $lastSize
            ) {
                $reasons[] = 'office_unsynced_workspace';
                continue;
            }

            if ($this->markClosedIfUnchanged($sessionId, $mtime, $size)) {
                @unlink($active);
                $reconciled++;
                error_log('[ArcadeCloud Office] sesión ready abandonada reconciliada sin cambios pendientes: ' . $sessionId);
            }
        }

        return [
            'reconciled' => $reconciled,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /** Read-only safety check, including when called from a gateway without the workspace. */
    public function readyWorkspaceIsSynced(array $row): bool
    {
        $sessionId = (string)($row['SessionId'] ?? '');
        $relative = (string)($row['WorkspaceRelative'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)
            || !str_starts_with($relative, 'sessions/' . $sessionId . '/')) return false;
        $path = $this->workspacePath($relative);
        if ($path === null || !is_file($path) || !is_readable($path)) return false;
        if (!is_file(dirname($path) . '/.arcadecloud-office-managed')) return false;
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        $size = @filesize($path);
        $digest = trim((string)@file_get_contents(dirname($path) . '/.arcadecloud-office-synced-sha256'));
        $currentDigest = @hash_file('sha256', $path);
        return preg_match('/^[a-f0-9]{64}$/', $digest) === 1
            && is_string($currentDigest) && hash_equals($digest, $currentDigest)
            && trim((string)($row['LastSyncedAt'] ?? '')) !== ''
            && $mtime !== false && $size !== false
            && $mtime === (int)($row['LastWorkspaceMtime'] ?? -1)
            && $size === (int)($row['LastWorkspaceSize'] ?? -1);
    }

    private function hasActiveLease(string $instanceId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM OfficeSessionLeases WHERE InstanceId=? AND ExpiresAt>UTC_TIMESTAMP() LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('No se pudo comprobar el lease Office.');
        $stmt->bind_param('s', $instanceId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo ejecutar la comprobación del lease Office.');
        }
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            throw new RuntimeException('No se pudo leer el lease Office.');
        }
        $active = is_array($result->fetch_row());
        $result->free();
        $stmt->close();
        return $active;
    }

    /** @return array<int,array<string,mixed>> */
    private function activeCandidates(string $instanceId): array
    {
        $stmt = $this->db->prepare(
            "SELECT SessionId,Status,WorkspaceRelative,LastWorkspaceMtime,LastWorkspaceSize,
                    LastSyncedAt,UpdatedAt
             FROM OfficeDocumentSessions
             WHERE InstanceId=? AND Status IN ('preparing','ready','syncing','conflict')
             ORDER BY id_ ASC"
        );
        if (!$stmt) throw new RuntimeException('No se pudieron consultar sesiones Office activas.');
        $stmt->bind_param('s', $instanceId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudieron ejecutar las sesiones Office activas.');
        }
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            throw new RuntimeException('No se pudieron leer sesiones Office activas.');
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'session_id' => (string)($row['SessionId'] ?? ''),
                'status' => (string)($row['Status'] ?? ''),
                'workspace_relative' => (string)($row['WorkspaceRelative'] ?? ''),
                'last_workspace_mtime' => (int)($row['LastWorkspaceMtime'] ?? 0),
                'last_workspace_size' => (int)($row['LastWorkspaceSize'] ?? 0),
                'last_synced_at' => (string)($row['LastSyncedAt'] ?? ''),
                'updated_at' => (string)($row['UpdatedAt'] ?? ''),
            ];
        }
        $result->free();
        $stmt->close();
        return $rows;
    }

    private function markFailed(string $sessionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE OfficeDocumentSessions
             SET Status='failed',ClosedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP()
             WHERE SessionId=? AND Status='preparing'
               AND (WorkspaceRelative IS NULL OR WorkspaceRelative='')
               AND UpdatedAt <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 900 SECOND)"
        );
        if (!$stmt) throw new RuntimeException('No se pudo reconciliar sesión Office preparing.');
        $stmt->bind_param('s', $sessionId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo cerrar sesión Office preparing abandonada.');
        }
        $stmt->close();
    }

    private function markClosedIfUnchanged(string $sessionId, int $mtime, int $size): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE OfficeDocumentSessions
             SET Status='closed',ClosedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP()
             WHERE SessionId=? AND Status='ready'
               AND LastSyncedAt IS NOT NULL
               AND LastWorkspaceMtime=? AND LastWorkspaceSize=?"
        );
        if (!$stmt) throw new RuntimeException('No se pudo preparar cierre seguro Office.');
        $stmt->bind_param('sii', $sessionId, $mtime, $size);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo cerrar sesión Office reconciliada.');
        }
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        return $changed;
    }

    private function workspacePath(string $relative): ?string
    {
        $relative = trim(str_replace('\\', '/', $relative));
        if (!preg_match('/\Asessions\/[a-f0-9]{32}\/[^\/\x00-\x1F\x7F]{1,220}\z/u', $relative)) {
            return null;
        }

        $sessionsRoot = realpath($this->workspaceRoot . '/sessions');
        $path = realpath($this->workspaceRoot . '/' . $relative);
        if (
            $sessionsRoot === false
            || $path === false
            || !str_starts_with($path, rtrim($sessionsRoot, '/') . '/')
        ) {
            return null;
        }
        return $path;
    }
}
