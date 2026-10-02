<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use mysqli;
use RuntimeException;

final class OfficeSessionLeaseRepository
{
    private const TTL_SECONDS = 660;

    public function __construct(private mysqli $db)
    {
        $this->ensureSchema();
    }

    public function claim(int $userId, string $instanceId, string $sessionKey): void
    {
        $this->assertInputs($userId, $instanceId, $sessionKey);

        $lockName = 'office-session-' . substr(hash('sha256', $instanceId), 0, 40);
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 5) AS acquired');
        if (!$lock) {
            throw new RuntimeException('No se pudo preparar el bloqueo de Office.');
        }
        $lock->bind_param('s', $lockName);
        $lock->execute();
        $row = $lock->get_result()?->fetch_assoc();
        $lock->close();

        if ((int)($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Office está siendo solicitado por otra sesión. Vuelve a intentar.');
        }

        try {
            $hash = hash('sha256', $sessionKey);
            $stmt = $this->db->prepare(
                'SELECT UserId,SessionKeyHash,(ExpiresAt > UTC_TIMESTAMP()) AS IsActive '
                . 'FROM OfficeSessionLeases WHERE InstanceId=? LIMIT 1'
            );
            if (!$stmt) {
                throw new RuntimeException('No se pudo consultar la sesión Office.');
            }
            $stmt->bind_param('s', $instanceId);
            $stmt->execute();
            $current = $stmt->get_result()?->fetch_assoc();
            $stmt->close();

            if (is_array($current) && (int)($current['IsActive'] ?? 0) === 1) {
                $sameUser = (int)($current['UserId'] ?? 0) === $userId;
                $sameKey = hash_equals((string)($current['SessionKeyHash'] ?? ''), $hash);
                if (!$sameUser || !$sameKey) {
                    throw new RuntimeException(
                        'Office ya está reservado por otra sesión. Espera a que termine o quede inactiva.'
                    );
                }
            }

            $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
            if (is_array($current)) {
                $update = $this->db->prepare(
                    'UPDATE OfficeSessionLeases '
                    . 'SET UserId=?,SessionKeyHash=?,LastActivityAt=UTC_TIMESTAMP(),'
                    . 'ExpiresAt=?,UpdatedAt=UTC_TIMESTAMP() WHERE InstanceId=?'
                );
                if (!$update) {
                    throw new RuntimeException('No se pudo renovar la sesión Office.');
                }
                $update->bind_param('isss', $userId, $hash, $expiresAt, $instanceId);
                $update->execute();
                $update->close();
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO OfficeSessionLeases '
                    . '(InstanceId,UserId,SessionKeyHash,ClaimedAt,LastActivityAt,ExpiresAt,UpdatedAt) '
                    . 'VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,UTC_TIMESTAMP())'
                );
                if (!$insert) {
                    throw new RuntimeException('No se pudo crear la sesión Office.');
                }
                $insert->bind_param('siss', $instanceId, $userId, $hash, $expiresAt);
                $insert->execute();
                $insert->close();
            }
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            if ($release) {
                $release->bind_param('s', $lockName);
                $release->execute();
                $release->close();
            }
        }
    }

    public function assertOwner(int $userId, string $instanceId, string $sessionKey): void
    {
        $this->assertInputs($userId, $instanceId, $sessionKey);
        $hash = hash('sha256', $sessionKey);

        $stmt = $this->db->prepare(
            'SELECT UserId,SessionKeyHash FROM OfficeSessionLeases '
            . 'WHERE InstanceId=? AND ExpiresAt>UTC_TIMESTAMP() LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar la sesión Office.');
        }
        $stmt->bind_param('s', $instanceId);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();

        if (
            !is_array($row)
            || (int)($row['UserId'] ?? 0) !== $userId
            || !hash_equals((string)($row['SessionKeyHash'] ?? ''), $hash)
        ) {
            throw new RuntimeException('La sesión Office no está autorizada para este navegador.');
        }
    }

    public function touch(int $userId, string $instanceId, string $sessionKey): void
    {
        $this->assertOwner($userId, $instanceId, $sessionKey);

        $hash = hash('sha256', $sessionKey);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        $stmt = $this->db->prepare(
            'UPDATE OfficeSessionLeases '
            . 'SET LastActivityAt=UTC_TIMESTAMP(),ExpiresAt=?,UpdatedAt=UTC_TIMESTAMP() '
            . 'WHERE InstanceId=? AND UserId=? AND SessionKeyHash=?'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo actualizar la actividad Office.');
        }
        $stmt->bind_param('ssis', $expiresAt, $instanceId, $userId, $hash);
        $stmt->execute();
        $stmt->close();
    }

    public function hasActiveForInstance(string $instanceId): bool
    {
        if (!preg_match('/^i-[0-9a-f]{8,17}$/i', $instanceId)) {
            throw new RuntimeException('Instancia Office inválida.');
        }
        $stmt = $this->db->prepare(
            'SELECT 1 FROM OfficeSessionLeases WHERE InstanceId=? AND ExpiresAt>UTC_TIMESTAMP() LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('No se pudo comprobar el lease Office.');
        $stmt->bind_param('s', $instanceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $active = $result && is_array($result->fetch_row());
        if ($result) $result->free();
        $stmt->close();
        return $active;
    }

    public function release(int $userId, string $instanceId, string $sessionKey): void
    {
        $this->assertInputs($userId, $instanceId, $sessionKey);
        $hash = hash('sha256', $sessionKey);
        $stmt = $this->db->prepare(
            'UPDATE OfficeSessionLeases SET ExpiresAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP() '
            . 'WHERE InstanceId=? AND UserId=? AND SessionKeyHash=?'
        );
        if (!$stmt) return;
        $stmt->bind_param('sis', $instanceId, $userId, $hash);
        $stmt->execute();
        $stmt->close();
    }

    private function assertInputs(int $userId, string $instanceId, string $sessionKey): void
    {
        if (
            $userId <= 0
            || !preg_match('/^i-[0-9a-f]{8,17}$/i', $instanceId)
            || !preg_match('/^[a-f0-9]{64}$/', $sessionKey)
        ) {
            throw new RuntimeException('Sesión Office inválida.');
        }
    }

    private function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS OfficeSessionLeases (
  id_ BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  InstanceId VARCHAR(32) NOT NULL,
  UserId INT NOT NULL,
  SessionKeyHash CHAR(64) NOT NULL,
  ClaimedAt DATETIME NOT NULL,
  LastActivityAt DATETIME NOT NULL,
  ExpiresAt DATETIME NOT NULL,
  UpdatedAt DATETIME NOT NULL,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_office_session_instance (InstanceId),
  KEY idx_office_session_expiry (ExpiresAt),
  KEY idx_office_session_user (UserId,ExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        if (!$this->db->query($sql)) {
            throw new RuntimeException('No se pudo preparar OfficeSessionLeases: ' . $this->db->error);
        }
    }
}
