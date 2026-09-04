<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use mysqli;
use RuntimeException;

final class FileSecurityRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findByKey(int $userId, string $key, ?string $requiredAccessType = null): ?array
    {
        [$route, $encrypted] = $this->splitKey($key);
        $accessSql = $requiredAccessType !== null ? ' AND AccessType=?' : '';

        $sql = "SELECT id_, Nombre, Encriptado, Ruta, AccessType, PasswordHash, SecureHint, Found
                FROM FileS3
                WHERE user_id_=? AND Found=1{$accessSql}
                  AND (Encriptado=? OR (Ruta=? AND Encriptado=?))
                ORDER BY id_ DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la búsqueda de seguridad: ' . $this->db->error);
        }

        if ($requiredAccessType !== null) {
            $stmt->bind_param('issss', $userId, $requiredAccessType, $key, $route, $encrypted);
        } else {
            $stmt->bind_param('isss', $userId, $key, $route, $encrypted);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    public function setSecure(int $userId, int $fileId, string $passwordHash, string $hint): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FileS3 SET AccessType='secure', PasswordHash=?, SecureHint=?, SecureUpdatedAt=NOW()
             WHERE id_=? AND user_id_=? AND Found=1"
        );
        $this->execute($stmt, 'ssii', [$passwordHash, $hint, $fileId, $userId]);
    }

    public function setUnlocked(int $userId, int $fileId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FileS3 SET AccessType='unlocked', SecureUpdatedAt=NOW()
             WHERE id_=? AND user_id_=? AND Found=1"
        );
        $this->execute($stmt, 'ii', [$fileId, $userId]);
    }

    public function setNormal(int $userId, int $fileId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FileS3 SET AccessType='normal', PasswordHash=NULL, SecureHint=NULL, SecureUpdatedAt=NOW()
             WHERE id_=? AND user_id_=? AND Found=1"
        );
        $this->execute($stmt, 'ii', [$fileId, $userId]);
    }

    private function execute(\mysqli_stmt|false $stmt, string $types, array $values): void
    {
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la actualización de seguridad: ' . $this->db->error);
        }
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute() || $stmt->errno) {
            $message = $stmt->error ?: 'Error al actualizar seguridad.';
            $stmt->close();
            throw new RuntimeException($message);
        }
        $stmt->close();
    }

    private function splitKey(string $key): array
    {
        $position = strrpos($key, '/');
        if ($position === false) {
            return ['', $key];
        }
        return [substr($key, 0, $position + 1), substr($key, $position + 1)];
    }
}
