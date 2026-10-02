<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use mysqli;
use RuntimeException;

final class OfficeSessionReconciler
{
    private const PREPARING_STALE_SECONDS = 900;
    private const READY_STALE_SECONDS = 180;
    private const PROCESS_HEARTBEAT_SECONDS = 45;

    private string $workspaceRoot;
    private OfficeDocumentSessionRepository $documents;
    private OfficeSessionLeaseRepository $leases;

    public function __construct(private mysqli $db)
    {
        $this->workspaceRoot = rtrim(
            trim((string)(getenv('ARCADECLOUD_OFFICE_WORKSPACE_ROOT')
                ?: '/var/lib/arcadecloud-office/phase1-workspace')),
            '/'
        );
        $this->documents = new OfficeDocumentSessionRepository($db);
        $this->leases = new OfficeSessionLeaseRepository($db);
    }

    /** @return array{reconciled:int,reasons:array<int,string>} */
    public function reconcile(string $instanceId): array
    {
        if (!preg_match('/^i-[0-9a-f]{8,17}$/i', $instanceId)) {
            throw new RuntimeException('Instancia Office inválida para reconciliación.');
        }

        $reconciled = 0;
        $reasons = [];
        $leaseActive = $this->leases->hasActiveForInstance($instanceId);

        foreach ($this->documents->activeCandidates($instanceId) as $session) {
            $sessionId = (string)($session['session_id'] ?? '');
            $status = (string)($session['status'] ?? '');
            $updatedAt = strtotime((string)($session['updated_at'] ?? '') . ' UTC');
            $age = $updatedAt === false ? 0 : max(0, time() - $updatedAt);

            if (
                $status === 'preparing'
                && trim((string)($session['workspace_relative'] ?? '')) === ''
                && $age >= self::PREPARING_STALE_SECONDS
            ) {
                $this->documents->markFailed($sessionId);
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
            $size = (int)(@filesize($path) ?: -1);
            $lastMtime = (int)($session['last_workspace_mtime'] ?? 0);
            $lastSize = (int)($session['last_workspace_size'] ?? -1);
            $lastSyncedAt = trim((string)($session['last_synced_at'] ?? ''));

            if (
                $lastSyncedAt === ''
                || $mtime <= 0
                || $size < 0
                || $mtime !== $lastMtime
                || $size !== $lastSize
            ) {
                $reasons[] = 'office_unsynced_workspace';
                continue;
            }

            if ($this->documents->markClosedIfUnchanged($sessionId, $mtime, $size)) {
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
