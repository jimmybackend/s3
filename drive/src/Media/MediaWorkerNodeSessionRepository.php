<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use mysqli;
use RuntimeException;

final class MediaWorkerNodeSessionRepository
{
    public function __construct(private mysqli $db)
    {
        $this->ensureSchema();
    }

    public function activeForInstance(string $instanceId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM MediaWorkerNodeSessions
             WHERE InstanceId=?
               AND Status IN ('starting','running','idle','stopping')
             ORDER BY id_ DESC
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo consultar la sesión del nodo multimedia.');
        $stmt->bind_param('s', $instanceId);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $this->normalize($row) : null;
    }

    public function create(
        int $userId,
        string $instanceId,
        string $region,
        string $instanceType,
        ?float $hourlyUsd
    ): array {
        $lockName = 'media-worker-' . substr(hash('sha256', $instanceId), 0, 40);
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 5) AS acquired');
        if (!$lock) throw new RuntimeException('No se pudo preparar el bloqueo del nodo multimedia.');
        $lock->bind_param('s', $lockName);
        $lock->execute();
        $row = $lock->get_result()?->fetch_assoc();
        $lock->close();
        if ((int)($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('El nodo multimedia está siendo solicitado por otra tarea. Vuelve a intentar.');
        }

        try {
            $active = $this->activeForInstance($instanceId);
            if ($active !== null) return $active;

            $sessionId = bin2hex(random_bytes(16));
            $status = 'starting';
            $stmt = $this->db->prepare(
                "INSERT INTO MediaWorkerNodeSessions
                 (SessionId,InstanceId,Region,StartedByUserId,InstanceType,HourlyUsd,Status,StartedAt,CreatedAt,UpdatedAt)
                 VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
            );
            if (!$stmt) throw new RuntimeException('No se pudo preparar la sesión del nodo multimedia.');
            $stmt->bind_param('sssisds', $sessionId, $instanceId, $region, $userId, $instanceType, $hourlyUsd, $status);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException('No se pudo crear la sesión del nodo multimedia: ' . $error);
            }
            $stmt->close();

            $active = $this->activeForInstance($instanceId);
            if ($active === null) throw new RuntimeException('No se pudo recuperar la sesión del nodo multimedia.');
            return $active;
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            if ($release) {
                $release->bind_param('s', $lockName);
                $release->execute();
                $release->close();
            }
        }
    }

    public function markRunning(string $sessionId): void
    {
        $this->simpleUpdate(
            "UPDATE MediaWorkerNodeSessions
             SET Status='running',IdleSince=NULL,UpdatedAt=UTC_TIMESTAMP()
             WHERE SessionId=? AND Status IN ('starting','running','idle')",
            $sessionId
        );
    }

    public function markIdle(string $sessionId): void
    {
        $this->simpleUpdate(
            "UPDATE MediaWorkerNodeSessions
             SET Status='idle',IdleSince=COALESCE(IdleSince,UTC_TIMESTAMP()),UpdatedAt=UTC_TIMESTAMP()
             WHERE SessionId=? AND Status IN ('starting','running','idle')",
            $sessionId
        );
    }

    public function clearIdle(string $sessionId): void
    {
        $this->markRunning($sessionId);
    }

    public function markStopRequested(string $sessionId): void
    {
        $this->simpleUpdate(
            "UPDATE MediaWorkerNodeSessions
             SET Status='stopping',StopRequestedAt=COALESCE(StopRequestedAt,UTC_TIMESTAMP()),UpdatedAt=UTC_TIMESTAMP()
             WHERE SessionId=? AND Status IN ('starting','running','idle')",
            $sessionId
        );
    }

    public function markStopped(string $sessionId): void
    {
        $this->simpleUpdate(
            "UPDATE MediaWorkerNodeSessions
             SET Status='stopped',StoppedAt=COALESCE(StoppedAt,UTC_TIMESTAMP()),UpdatedAt=UTC_TIMESTAMP()
             WHERE SessionId=?",
            $sessionId
        );
    }

    public function markFailed(string $sessionId, string $message): void
    {
        $message = mb_substr(trim($message), 0, 4000);
        $stmt = $this->db->prepare(
            "UPDATE MediaWorkerNodeSessions
             SET Status='failed',LastError=?,UpdatedAt=UTC_TIMESTAMP()
             WHERE SessionId=?"
        );
        if (!$stmt) return;
        $stmt->bind_param('ss', $message, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    private function simpleUpdate(string $sql, string $sessionId): void
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return;
        $stmt->bind_param('s', $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    private function normalize(array $row): array
    {
        return [
            'id' => (int)($row['id_'] ?? 0),
            'session_id' => (string)($row['SessionId'] ?? ''),
            'instance_id' => (string)($row['InstanceId'] ?? ''),
            'region' => (string)($row['Region'] ?? ''),
            'started_by_user_id' => (int)($row['StartedByUserId'] ?? 0),
            'instance_type' => (string)($row['InstanceType'] ?? ''),
            'hourly_usd' => $row['HourlyUsd'] !== null ? (float)$row['HourlyUsd'] : null,
            'status' => (string)($row['Status'] ?? ''),
            'started_at' => (string)($row['StartedAt'] ?? ''),
            'idle_since' => (string)($row['IdleSince'] ?? ''),
            'stop_requested_at' => (string)($row['StopRequestedAt'] ?? ''),
            'stopped_at' => (string)($row['StoppedAt'] ?? ''),
            'last_error' => (string)($row['LastError'] ?? ''),
            'created_at' => (string)($row['CreatedAt'] ?? ''),
            'updated_at' => (string)($row['UpdatedAt'] ?? ''),
        ];
    }

    private function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS MediaWorkerNodeSessions (
  id_ BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  SessionId CHAR(32) NOT NULL,
  InstanceId VARCHAR(32) NOT NULL,
  Region VARCHAR(32) NOT NULL,
  StartedByUserId INT NOT NULL,
  InstanceType VARCHAR(64) NOT NULL DEFAULT '',
  HourlyUsd DECIMAL(14,8) NULL,
  Status VARCHAR(20) NOT NULL,
  StartedAt DATETIME NOT NULL,
  IdleSince DATETIME NULL,
  StopRequestedAt DATETIME NULL,
  StoppedAt DATETIME NULL,
  LastError TEXT NULL,
  CreatedAt DATETIME NOT NULL,
  UpdatedAt DATETIME NOT NULL,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_media_worker_session (SessionId),
  KEY idx_media_worker_instance_status (InstanceId, Status, id_)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        if (!$this->db->query($sql)) {
            throw new RuntimeException('No se pudo preparar MediaWorkerNodeSessions: ' . $this->db->error);
        }
    }
}
