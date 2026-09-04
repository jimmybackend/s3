<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

final class UserStorageProvisioner
{
    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private UserStoragePath $paths
    ) {
    }

    public function ensureRoot(int $userId): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido para provisionar almacenamiento.');
        }

        $root = $this->paths->rootForUser($userId);

        if ($this->rootIsRegistered($userId, $root)) {
            return $root;
        }

        // S3 no tiene carpetas reales. Para un usuario nuevo creamos un
        // objeto vacío con slash final para que su raíz exista aun sin archivos.
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $root,
            'Body' => '',
            'ContentType' => 'application/x-directory',
            'Metadata' => [
                'drive-user-id' => (string) $userId,
                'drive-root' => '1',
            ],
        ]);

        $name = rtrim($root, '/');
        $stmt = $this->db->prepare(
            "INSERT INTO S3Folders
                (user_id_, Prefix, Nombre, ParentPrefix, Found, AccessType, CreatedAt, UpdatedAt)
             VALUES (?, ?, ?, NULL, 1, 'normal', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                Nombre = VALUES(Nombre),
                ParentPrefix = NULL,
                Found = 1,
                UpdatedAt = NOW()"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo registrar la raíz del usuario: ' . $this->db->error);
        }

        $stmt->bind_param('iss', $userId, $root, $name);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo registrar la raíz del usuario: ' . $error);
        }
        $stmt->close();

        return $root;
    }

    private function rootIsRegistered(int $userId, string $root): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id_, Found FROM S3Folders WHERE user_id_ = ? AND Prefix = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo verificar la raíz del usuario: ' . $this->db->error);
        }

        $stmt->bind_param('is', $userId, $root);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        if ((int) ($row['Found'] ?? 0) !== 1) {
            $update = $this->db->prepare(
                'UPDATE S3Folders SET Found = 1, UpdatedAt = NOW() WHERE id_ = ? AND user_id_ = ?'
            );
            if (!$update) {
                throw new RuntimeException('No se pudo reactivar la raíz del usuario: ' . $this->db->error);
            }
            $id = (int) $row['id_'];
            $update->bind_param('ii', $id, $userId);
            $update->execute();
            $update->close();
        }

        return true;
    }
}
