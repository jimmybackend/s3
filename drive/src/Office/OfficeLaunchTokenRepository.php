<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use mysqli;
use RuntimeException;

final class OfficeLaunchTokenRepository
{
    public function __construct(private mysqli $db)
    {
        $this->ensureSchema();
    }

    public function issue(int $userId, int $ttlSeconds = 120): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido para iniciar Office.');
        }

        $ttlSeconds = max(30, min(600, $ttlSeconds));
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $this->cleanup();

        $stmt = $this->db->prepare(
            'INSERT INTO OfficeLaunchTokens '
            . '(TokenHash,UserId,CreatedAt,ExpiresAt) '
            . 'VALUES (?,?,UTC_TIMESTAMP(),?)'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar el lanzamiento de Office.');
        }
        $stmt->bind_param('sis', $hash, $userId, $expiresAt);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo crear el lanzamiento de Office: ' . $error);
        }
        $stmt->close();

        return $token;
    }

    public function consume(string $token): int
    {
        $token = strtolower(trim($token));
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return 0;
        }

        $hash = hash('sha256', $token);
        $this->db->begin_transaction();

        try {
            $stmt = $this->db->prepare(
                'SELECT UserId FROM OfficeLaunchTokens '
                . 'WHERE TokenHash=? AND ConsumedAt IS NULL AND ExpiresAt>UTC_TIMESTAMP() '
                . 'LIMIT 1 FOR UPDATE'
            );
            if (!$stmt) {
                throw new RuntimeException('No se pudo validar el lanzamiento de Office.');
            }
            $stmt->bind_param('s', $hash);
            $stmt->execute();
            $row = $stmt->get_result()?->fetch_assoc();
            $stmt->close();

            $userId = is_array($row) ? (int)($row['UserId'] ?? 0) : 0;
            if ($userId <= 0) {
                $this->db->rollback();
                return 0;
            }

            $update = $this->db->prepare(
                'UPDATE OfficeLaunchTokens SET ConsumedAt=UTC_TIMESTAMP() '
                . 'WHERE TokenHash=? AND ConsumedAt IS NULL'
            );
            if (!$update) {
                throw new RuntimeException('No se pudo consumir el lanzamiento de Office.');
            }
            $update->bind_param('s', $hash);
            $update->execute();
            $changed = $update->affected_rows === 1;
            $update->close();

            if (!$changed) {
                $this->db->rollback();
                return 0;
            }

            $this->db->commit();
            return $userId;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function cleanup(): void
    {
        $this->db->query(
            'DELETE FROM OfficeLaunchTokens '
            . 'WHERE ExpiresAt < (UTC_TIMESTAMP() - INTERVAL 1 DAY) '
            . 'OR ConsumedAt < (UTC_TIMESTAMP() - INTERVAL 1 DAY)'
        );
    }

    private function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS OfficeLaunchTokens (
  id_ BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  TokenHash CHAR(64) NOT NULL,
  UserId INT NOT NULL,
  CreatedAt DATETIME NOT NULL,
  ExpiresAt DATETIME NOT NULL,
  ConsumedAt DATETIME NULL,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_office_launch_token_hash (TokenHash),
  KEY idx_office_launch_expiry (ExpiresAt),
  KEY idx_office_launch_user (UserId,CreatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
        if (!$this->db->query($sql)) {
            throw new RuntimeException('No se pudo preparar OfficeLaunchTokens: ' . $this->db->error);
        }
    }
}
