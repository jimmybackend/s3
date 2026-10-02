<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\System;

use mysqli;
use RuntimeException;
use Throwable;

/**
 * Cross-process admission/stop mutex backed by the existing shared MySQL/MariaDB
 * connection. No schema is required. The lock is advisory and scoped per
 * FastDrive instance so unrelated compute nodes do not block each other.
 */
final class ComputeNodeAdmissionLock
{
    public function __construct(private mysqli $db)
    {
    }

    public function synchronized(string $instanceId, callable $callback, int $timeoutSeconds = 5): mixed
    {
        $instanceId = trim($instanceId);
        if ($instanceId === '') {
            return $callback();
        }

        $timeoutSeconds = max(0, min(15, $timeoutSeconds));
        $name = 'arcadecloud:compute:' . substr(hash('sha256', strtolower($instanceId)), 0, 32);
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, ?) AS acquired');
        if (!$stmt) {
            throw new RuntimeException('No se pudo coordinar el acceso al nodo de cómputo.');
        }

        try {
            $stmt->bind_param('si', $name, $timeoutSeconds);
            if (!$stmt->execute()) {
                throw new RuntimeException('No se pudo coordinar el acceso al nodo de cómputo.');
            }
            $row = $stmt->get_result()?->fetch_assoc();
            if ((int)($row['acquired'] ?? 0) !== 1) {
                throw new RuntimeException('El nodo está cambiando de estado. Vuelve a intentarlo en unos segundos.');
            }
        } finally {
            $stmt->close();
        }

        try {
            return $callback();
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                if ($release) {
                    $release->bind_param('s', $name);
                    $release->execute();
                    $release->close();
                }
            } catch (Throwable $error) {
                error_log('[ArcadeCloud compute-lock] release failed: ' . $error->getMessage());
            }
        }
    }
}
