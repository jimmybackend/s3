<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use mysqli;
use RuntimeException;

final class FolderMutationRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function exists(int $userId, string $prefix): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM S3Folders WHERE user_id_ = ? AND Prefix = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('No se pudo consultar la carpeta: ' . $this->db->error);
        $stmt->bind_param('is', $userId, $prefix);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    public function visibleNameExists(int $userId, string $parent, string $name, ?string $excludePrefix = null): bool
    {
        $sql = 'SELECT 1 FROM S3Folders WHERE user_id_ = ? AND ParentPrefix = ? AND Nombre = ? AND Found = 1';
        if ($excludePrefix !== null) $sql .= ' AND Prefix <> ?';
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('No se pudo validar el nombre de carpeta: ' . $this->db->error);
        if ($excludePrefix !== null) {
            $stmt->bind_param('isss', $userId, $parent, $name, $excludePrefix);
        } else {
            $stmt->bind_param('iss', $userId, $parent, $name);
        }
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    public function upsert(int $userId, string $prefix, string $name, ?string $parent): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO S3Folders
                (user_id_, Prefix, Nombre, ParentPrefix, Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt)
             VALUES (?, ?, ?, ?, 1, 'normal', NULL, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                Nombre = IF(Nombre IS NULL OR Nombre = '', VALUES(Nombre), Nombre),
                ParentPrefix = VALUES(ParentPrefix), Found = 1, UpdatedAt = CURRENT_TIMESTAMP"
        );
        if (!$stmt) throw new RuntimeException('No se pudo registrar la carpeta: ' . $this->db->error);
        $stmt->bind_param('isss', $userId, $prefix, $name, $parent);
        $this->execute($stmt, 'No se pudo registrar la carpeta');
    }

    public function requireActive(int $userId, string $prefix): array
    {
        $stmt = $this->db->prepare(
            'SELECT id_, user_id_, Prefix, ParentPrefix, Nombre FROM S3Folders WHERE user_id_ = ? AND Prefix = ? AND Found = 1 LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('No se pudo localizar la carpeta: ' . $this->db->error);
        $stmt->bind_param('is', $userId, $prefix);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new RuntimeException('Carpeta no encontrada.');
        return $row;
    }

    public function renameVisible(int $userId, int $id, string $name): void
    {
        $stmt = $this->db->prepare(
            'UPDATE S3Folders SET Nombre = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE id_ = ? AND user_id_ = ?'
        );
        if (!$stmt) throw new RuntimeException('No se pudo preparar el renombrado de carpeta: ' . $this->db->error);
        $stmt->bind_param('sii', $name, $id, $userId);
        $this->execute($stmt, 'No se pudo renombrar la carpeta');
    }

    public function moveTree(int $userId, string $oldPrefix, string $newPrefix): array
    {
        $this->db->begin_transaction();
        try {
            $folders = $this->foldersUnder($userId, $oldPrefix);
            if ($folders === []) throw new RuntimeException('La carpeta origen no existe en S3Folders.');

            $folderStmt = $this->db->prepare(
                'UPDATE S3Folders SET Prefix = ?, ParentPrefix = ?, Found = 1, UpdatedAt = CURRENT_TIMESTAMP WHERE id_ = ? AND user_id_ = ?'
            );
            if (!$folderStmt) throw new RuntimeException('No se pudo preparar la actualización de carpetas: ' . $this->db->error);

            $foldersUpdated = 0;
            foreach ($folders as $folder) {
                $current = $this->normalizePrefix((string)$folder['Prefix']);
                $suffix = substr($current, strlen($oldPrefix));
                $next = $this->normalizePrefix($newPrefix . $suffix);
                $parent = $current === $oldPrefix
                    ? $this->parentPrefix($newPrefix)
                    : $this->rewriteParent((string)($folder['ParentPrefix'] ?? ''), $oldPrefix, $newPrefix);
                $id = (int)$folder['id_'];
                $folderStmt->bind_param('ssii', $next, $parent, $id, $userId);
                if (!$folderStmt->execute()) throw new RuntimeException('No se pudo actualizar S3Folders: ' . $folderStmt->error);
                $foldersUpdated++;
            }
            $folderStmt->close();

            $files = $this->filesUnder($userId, $oldPrefix);
            $fileStmt = $this->db->prepare(
                'UPDATE FileS3 SET Ruta = ?, Encriptado = ?, Found = 1 WHERE id_ = ? AND user_id_ = ?'
            );
            if (!$fileStmt) throw new RuntimeException('No se pudo preparar la actualización de archivos: ' . $this->db->error);

            $filesUpdated = 0;
            foreach ($files as $file) {
                $route = $this->normalizePrefix((string)$file['Ruta']);
                if (!str_starts_with($route, $oldPrefix)) {
                    throw new RuntimeException('Ruta de archivo fuera del árbol origen.');
                }
                $basename = basename(str_replace('\\', '/', trim((string)$file['Encriptado'])));
                if ($basename === '') throw new RuntimeException('Archivo con Encriptado inválido.');
                $nextRoute = $this->normalizePrefix($newPrefix . substr($route, strlen($oldPrefix)));
                $nextEncrypted = $this->normalizeKey($nextRoute . $basename);
                $id = (int)$file['id_'];
                $fileStmt->bind_param('ssii', $nextRoute, $nextEncrypted, $id, $userId);
                if (!$fileStmt->execute()) throw new RuntimeException('No se pudo actualizar FileS3: ' . $fileStmt->error);
                $filesUpdated++;
            }
            $fileStmt->close();

            $this->db->commit();
            return ['foldersUpdated' => $foldersUpdated, 'filesUpdated' => $filesUpdated];
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function deleteTree(int $userId, string $prefix): array
    {
        $this->db->begin_transaction();
        try {
            $stmtFiles = $this->db->prepare(
                "DELETE FROM FileS3 WHERE user_id_ = ? AND (Ruta = ? OR Ruta LIKE CONCAT(?, '%'))"
            );
            if (!$stmtFiles) throw new RuntimeException('No se pudo preparar delete FileS3: ' . $this->db->error);
            $stmtFiles->bind_param('iss', $userId, $prefix, $prefix);
            $stmtFiles->execute();
            $files = $stmtFiles->affected_rows;
            $stmtFiles->close();

            $stmtFolders = $this->db->prepare(
                "DELETE FROM S3Folders WHERE user_id_ = ? AND (Prefix = ? OR Prefix LIKE CONCAT(?, '%'))"
            );
            if (!$stmtFolders) throw new RuntimeException('No se pudo preparar delete S3Folders: ' . $this->db->error);
            $stmtFolders->bind_param('iss', $userId, $prefix, $prefix);
            $stmtFolders->execute();
            $folders = $stmtFolders->affected_rows;
            $stmtFolders->close();

            $this->db->commit();
            return ['files' => $files, 'folders' => $folders];
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function foldersUnder(int $userId, string $prefix): array
    {
        $stmt = $this->db->prepare(
            "SELECT id_, Prefix, ParentPrefix, Nombre FROM S3Folders WHERE user_id_ = ? AND (Prefix = ? OR Prefix LIKE CONCAT(?, '%')) ORDER BY LENGTH(Prefix) ASC"
        );
        if (!$stmt) throw new RuntimeException('No se pudo leer S3Folders: ' . $this->db->error);
        $stmt->bind_param('iss', $userId, $prefix, $prefix);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function filesUnder(int $userId, string $prefix): array
    {
        $stmt = $this->db->prepare(
            "SELECT id_, Ruta, Encriptado FROM FileS3 WHERE user_id_ = ? AND Found = 1 AND (Ruta = ? OR Ruta LIKE CONCAT(?, '%'))"
        );
        if (!$stmt) throw new RuntimeException('No se pudo leer FileS3: ' . $this->db->error);
        $stmt->bind_param('iss', $userId, $prefix, $prefix);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function rewriteParent(string $parent, string $oldPrefix, string $newPrefix): ?string
    {
        $parent = trim($parent);
        if ($parent === '') return null;
        $parent = $this->normalizePrefix($parent);
        return str_starts_with($parent, $oldPrefix)
            ? $this->normalizePrefix($newPrefix . substr($parent, strlen($oldPrefix)))
            : $parent;
    }

    private function parentPrefix(string $prefix): ?string
    {
        $prefix = rtrim($this->normalizePrefix($prefix), '/');
        $pos = strrpos($prefix, '/');
        return $pos === false ? null : substr($prefix, 0, $pos + 1);
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = $this->normalizeKey($prefix);
        return rtrim($prefix, '/') . '/';
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }

    private function execute(\mysqli_stmt $stmt, string $context): void
    {
        if (!$stmt->execute()) {
            $error = $stmt->error ?: $this->db->error;
            $stmt->close();
            throw new RuntimeException($context . ': ' . $error);
        }
        $stmt->close();
    }
}
