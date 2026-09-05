<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use mysqli;
use RuntimeException;

final class UploadCatalogRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function insert(array $row): int
    {
        $sql = "
            INSERT INTO FileS3
                (Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt, Fecha, user_id_)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar el registro de FileS3: ' . $this->db->error);
        }

        $nombre = (string)($row['Nombre'] ?? '');
        $encriptado = (string)($row['Encriptado'] ?? '');
        $tamano = (int)($row['Tamano'] ?? 0);
        $metadatos = array_key_exists('Metadatos', $row) ? $row['Metadatos'] : null;
        $ruta = (string)($row['Ruta'] ?? '');
        $found = (int)($row['Found'] ?? 1);
        $accessType = (string)($row['AccessType'] ?? 'normal');
        $passwordHash = $row['PasswordHash'] ?? null;
        $secureHint = $row['SecureHint'] ?? null;
        $secureUpdatedAt = $row['SecureUpdatedAt'] ?? null;
        $fecha = (string)($row['Fecha'] ?? date('Y-m-d H:i:s'));
        $userId = (int)($row['user_id_'] ?? 0);

        $stmt->bind_param(
            'ssississsssi',
            $nombre,
            $encriptado,
            $tamano,
            $metadatos,
            $ruta,
            $found,
            $accessType,
            $passwordHash,
            $secureHint,
            $secureUpdatedAt,
            $fecha,
            $userId
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo registrar FileS3: ' . $error);
        }

        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }
}
