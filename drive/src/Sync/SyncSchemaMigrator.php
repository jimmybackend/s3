<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sync;

use mysqli;
use RuntimeException;

final class SyncSchemaMigrator
{
    private const OLD_INDEX = 'uq_files3_user_key';
    private const NEW_INDEX = 'uq_files3_user_path_key';

    public function __construct(private mysqli $db)
    {
    }

    /** @return array<string,mixed> */
    public function migrate(): array
    {
        if (!$this->tableExists('FileS3')) {
            throw new RuntimeException('La tabla FileS3 no existe.');
        }

        if ($this->indexExists(self::NEW_INDEX)) {
            return [
                'ok' => true,
                'changed' => false,
                'index' => self::NEW_INDEX,
                'message' => 'El índice por usuario+ruta+archivo ya está instalado.',
            ];
        }

        $duplicate = $this->db->query(
            "SELECT user_id_, Ruta, Encriptado, COUNT(*) AS total
             FROM FileS3
             GROUP BY user_id_, Ruta, Encriptado
             HAVING COUNT(*) > 1
             LIMIT 1"
        );
        if (!$duplicate) {
            throw new RuntimeException('No se pudo validar FileS3 antes de migrar: ' . $this->db->error);
        }
        $row = $duplicate->fetch_assoc();
        $duplicate->free();
        if ($row) {
            throw new RuntimeException(
                'FileS3 contiene identidades físicas duplicadas para el mismo usuario y ruta; ' .
                'la migración se detuvo sin modificar índices.'
            );
        }

        $hasOld = $this->indexExists(self::OLD_INDEX);
        $sql = 'ALTER TABLE FileS3 ';
        if ($hasOld) {
            $sql .= 'DROP INDEX `' . self::OLD_INDEX . '`, ';
        }
        $sql .= 'ADD UNIQUE KEY `' . self::NEW_INDEX . '` (`user_id_`,`Ruta`,`Encriptado`)';

        if (!$this->db->query($sql)) {
            throw new RuntimeException('No se pudo migrar la identidad FileS3: ' . $this->db->error);
        }

        return [
            'ok' => true,
            'changed' => true,
            'dropped_old_index' => $hasOld,
            'index' => self::NEW_INDEX,
            'message' => 'FileS3 ahora identifica archivos por usuario+ruta+nombre físico.',
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo comprobar la tabla de sincronización.');
        }
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return (int)$count > 0;
    }

    private function indexExists(string $index): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'FileS3\' AND INDEX_NAME=?'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo comprobar el índice FileS3.');
        }
        $stmt->bind_param('s', $index);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return (int)$count > 0;
    }
}
