<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sync;

use mysqli;
use RuntimeException;

final class SyncRepository
{
    public function __construct(private mysqli $db)
    {
        $this->ensureSeenTable();
    }

    public function begin(): void
    {
        $this->db->begin_transaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        try {
            $this->db->rollback();
        } catch (\Throwable) {
        }
    }

    /*
     * Compatibilidad con sincronizador anterior.
     */
    public function resetFound(int $userId): void
    {
        $this->exec(
            'UPDATE FileS3 SET Found=0 WHERE user_id_=?',
            [$userId],
            'i'
        );

        $this->exec(
            'UPDATE S3Folders SET Found=0 WHERE user_id_=?',
            [$userId],
            'i'
        );
    }

    public function purgeMissing(int $userId): void
    {
        $this->exec(
            'DELETE FROM FileS3 WHERE user_id_=? AND Found=0',
            [$userId],
            'i'
        );

        $this->exec(
            'DELETE FROM S3Folders WHERE user_id_=? AND Found=0',
            [$userId],
            'i'
        );
    }

    /*
     * ============================================================
     * STAGING DE SINCRONIZACION
     * ============================================================
     *
     * Durante una sincronización por lotes registramos aquí todo
     * lo realmente observado en S3.
     *
     * No modificamos Found=0 al iniciar.
     * Por tanto, una conexión interrumpida no hace desaparecer
     * archivos del Drive.
     * ============================================================
     */
    private function ensureSeenTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS S3SyncSeen (
                sync_id CHAR(32) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                kind VARCHAR(10) NOT NULL,
                key_hash CHAR(64) NOT NULL,
                object_key TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (
                    sync_id,
                    user_id,
                    kind,
                    key_hash
                ),

                KEY idx_sync_user_created (
                    user_id,
                    created_at
                )
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
        ";

        if (!$this->db->query($sql)) {
            throw new RuntimeException(
                'No se pudo preparar staging de sincronización: ' .
                $this->db->error
            );
        }
    }

    public function cleanupExpiredSeen(int $userId): void
    {
        $this->exec(
            "DELETE FROM S3SyncSeen
             WHERE user_id=?
               AND created_at < (NOW() - INTERVAL 1 DAY)",
            [$userId],
            'i'
        );
    }

    public function markSeen(
        string $syncId,
        int $userId,
        string $kind,
        string $key
    ): void {
        $hash = hash('sha256', $key);

        $this->exec(
            "INSERT INTO S3SyncSeen
                (sync_id,user_id,kind,key_hash,object_key,created_at)
             VALUES
                (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
                object_key=VALUES(object_key),
                created_at=NOW()",
            [
                $syncId,
                $userId,
                $kind,
                $hash,
                $key
            ],
            'sisss'
        );
    }

    /*
     * Solamente se ejecuta cuando se llegó al final de TODAS
     * las páginas S3.
     */
    public function finalizeSync(
        int $userId,
        string $syncId
    ): array {
        $sqlFiles = "
            DELETE f
            FROM FileS3 f
            LEFT JOIN S3SyncSeen s
              ON s.sync_id = ?
             AND s.user_id = f.user_id_
             AND s.kind = 'file'
             AND s.key_hash = SHA2(f.Encriptado, 256)
             AND s.object_key = f.Encriptado
            WHERE f.user_id_ = ?
              AND s.key_hash IS NULL
        ";

        $stmt = $this->prepare($sqlFiles);
        $stmt->bind_param('si', $syncId, $userId);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }

        $filesRemoved = $stmt->affected_rows;
        $stmt->close();

        $sqlFolders = "
            DELETE f
            FROM S3Folders f
            LEFT JOIN S3SyncSeen s
              ON s.sync_id = ?
             AND s.user_id = f.user_id_
             AND s.kind = 'folder'
             AND s.key_hash = SHA2(f.Prefix, 256)
             AND s.object_key = f.Prefix
            WHERE f.user_id_ = ?
              AND s.key_hash IS NULL
        ";

        $stmt = $this->prepare($sqlFolders);
        $stmt->bind_param('si', $syncId, $userId);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }

        $foldersRemoved = $stmt->affected_rows;
        $stmt->close();

        $this->exec(
            'DELETE FROM S3SyncSeen WHERE sync_id=? AND user_id=?',
            [$syncId, $userId],
            'si'
        );

        return [
            'files_removed' => $filesRemoved,
            'folders_removed' => $foldersRemoved
        ];
    }

    /*
     * Si MySQL ya conoce el nombre visible, lo usamos.
     * Así NO hacemos HeadObject a S3 por cada archivo histórico.
     */
    public function existingVisibleName(
        int $userId,
        string $key
    ): ?string {
        $pos = strrpos($key, '/');

        $dir = $pos === false
            ? ''
            : substr($key, 0, $pos + 1);

        if ($dir !== '') {
            $dir = rtrim($dir, '/') . '/';
        }

        $base = $pos === false
            ? $key
            : substr($key, $pos + 1);

        $row = $this->one(
            "SELECT Nombre
             FROM FileS3
             WHERE user_id_=?
               AND (
                    Encriptado=?
                    OR (Ruta=? AND Encriptado=?)
               )
             ORDER BY
               CASE WHEN Encriptado=? THEN 0 ELSE 1 END
             LIMIT 1",
            [
                $userId,
                $key,
                $dir,
                $base,
                $key
            ],
            'issss'
        );

        if (!$row) {
            return null;
        }

        $name = trim((string)($row['Nombre'] ?? ''));

        return $name !== '' ? $name : null;
    }

    public function upsertFolder(
        int $userId,
        string $prefix,
        string $name,
        ?string $parent
    ): void {
        $row = $this->one(
            'SELECT id_
             FROM S3Folders
             WHERE user_id_=? AND Prefix=?
             LIMIT 1',
            [$userId, $prefix],
            'is'
        );

        if ($row) {
            $id = (int)$row['id_'];

            /*
             * Nombre NO se modifica:
             * MySQL conserva el nombre visible del usuario.
             */
            $this->exec(
                'UPDATE S3Folders
                 SET Found=1,
                     ParentPrefix=?,
                     UpdatedAt=NOW()
                 WHERE id_=? AND user_id_=?',
                [$parent, $id, $userId],
                'sii'
            );

            return;
        }

        $this->exec(
            "INSERT INTO S3Folders
                (
                    user_id_,
                    Prefix,
                    Nombre,
                    ParentPrefix,
                    Found,
                    AccessType,
                    CreatedAt,
                    UpdatedAt
                )
             VALUES
                (?,?,?,?,1,'normal',NOW(),NOW())",
            [
                $userId,
                $prefix,
                $name,
                $parent
            ],
            'isss'
        );
    }

    public function upsertFile(
        int $userId,
        string $key,
        int $size,
        ?string $recoveredName = null
    ): void {
        $pos = strrpos($key, '/');

        $dir = $pos === false
            ? ''
            : substr($key, 0, $pos + 1);

        $dir = $dir !== ''
            ? rtrim($dir, '/') . '/'
            : '';

        $base = $pos === false
            ? $key
            : substr($key, $pos + 1);

        $visible = trim((string)$recoveredName);

        if ($visible === '') {
            $visible = $base;
        }

        $row = $this->one(
            'SELECT id_
             FROM FileS3
             WHERE user_id_=? AND Encriptado=?
             LIMIT 1',
            [$userId, $key],
            'is'
        );

        /*
         * Compatibilidad con registros históricos donde
         * Encriptado contenía solamente basename.
         *
         * Ya NO dependemos de Found=0.
         */
        if (!$row) {
            $legacy = $this->all(
                "SELECT id_,Encriptado
                 FROM FileS3
                 WHERE user_id_=?
                   AND Ruta=?
                   AND (
                       Encriptado=?
                       OR Encriptado LIKE ? ESCAPE '!'
                   )
                 ORDER BY id_ ASC
                 LIMIT 2",
                [
                    $userId,
                    $dir,
                    $base,
                    '%/' . $this->likeEscape($base)
                ],
                'isss'
            );

            if (count($legacy) === 1) {
                $row = $legacy[0];
            }
        }

        if ($row) {
            $id = (int)$row['id_'];

            $this->exec(
                "UPDATE FileS3
                 SET Encriptado=?,
                     Tamano=?,
                     Ruta=?,
                     Nombre=IF(
                         Nombre IS NULL OR Nombre='',
                         ?,
                         Nombre
                     ),
                     Found=1
                 WHERE id_=? AND user_id_=?",
                [
                    $key,
                    $size,
                    $dir,
                    $visible,
                    $id,
                    $userId
                ],
                'sissii'
            );

            return;
        }

        $this->exec(
            "INSERT INTO FileS3
                (
                    Nombre,
                    Encriptado,
                    Tamano,
                    Metadatos,
                    Ruta,
                    Found,
                    AccessType,
                    Fecha,
                    user_id_
                )
             VALUES
                (?,?,?,NULL,?,1,'normal',NOW(),?)",
            [
                $visible,
                $key,
                $size,
                $dir,
                $userId
            ],
            'ssisi'
        );
    }

    public function status(int $userId): array
    {
        return [
            'files_total' => $this->scalar(
                'SELECT COUNT(*) FROM FileS3 WHERE user_id_=?',
                [$userId],
                'i'
            ),

            'files_found' => $this->scalar(
                'SELECT COUNT(*) FROM FileS3
                 WHERE user_id_=? AND Found=1',
                [$userId],
                'i'
            ),

            'folders_total' => $this->scalar(
                'SELECT COUNT(*) FROM S3Folders WHERE user_id_=?',
                [$userId],
                'i'
            ),

            'folders_found' => $this->scalar(
                'SELECT COUNT(*) FROM S3Folders
                 WHERE user_id_=? AND Found=1',
                [$userId],
                'i'
            ),

            'bytes_total' => $this->scalar(
                'SELECT COALESCE(SUM(Tamano),0)
                 FROM FileS3
                 WHERE user_id_=? AND Found=1',
                [$userId],
                'i'
            )
        ];
    }

    private function exec(
        string $sql,
        array $bind = [],
        string $types = ''
    ): void {
        $stmt = $this->prepare($sql);

        if ($bind) {
            $stmt->bind_param(
                $types,
                ...$bind
            );
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            throw new RuntimeException(
                $error
            );
        }

        $stmt->close();
    }

    private function one(
        string $sql,
        array $bind = [],
        string $types = ''
    ): ?array {
        $rows = $this->all(
            $sql,
            $bind,
            $types
        );

        return $rows[0] ?? null;
    }

    private function all(
        string $sql,
        array $bind = [],
        string $types = ''
    ): array {
        $stmt = $this->prepare($sql);

        if ($bind) {
            $stmt->bind_param(
                $types,
                ...$bind
            );
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            throw new RuntimeException(
                $error
            );
        }

        $result = $stmt->get_result();
        $rows = [];

        while (
            $result &&
            ($row = $result->fetch_assoc())
        ) {
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    }

    private function scalar(
        string $sql,
        array $bind = [],
        string $types = ''
    ): int {
        $stmt = $this->prepare($sql);

        if ($bind) {
            $stmt->bind_param(
                $types,
                ...$bind
            );
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            throw new RuntimeException(
                $error
            );
        }

        $stmt->bind_result($value);
        $stmt->fetch();
        $stmt->close();

        return (int)($value ?? 0);
    }

    private function prepare(
        string $sql
    ): \mysqli_stmt {
        $stmt = $this->db->prepare(
            $sql
        );

        if (!$stmt) {
            throw new RuntimeException(
                'SQL prepare failed: ' .
                $this->db->error
            );
        }

        return $stmt;
    }

    private function likeEscape(
        string $value
    ): string {
        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value
        );
    }
}
