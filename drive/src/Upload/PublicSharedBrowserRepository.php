<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use mysqli;
use RuntimeException;

final class PublicSharedBrowserRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function visibleName(string $physicalName, string $route): ?string
    {
        $physicalName = trim($physicalName);
        $route = trim($route);

        if ($physicalName === '' || $route === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT Nombre
             FROM FileS3
             WHERE Encriptado = ?
               AND Ruta = ?
             ORDER BY id_ DESC
             LIMIT 1'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo consultar el nombre visible del archivo: ' . $this->db->error
            );
        }

        $stmt->bind_param('ss', $physicalName, $route);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException(
                'No se pudo consultar el nombre visible del archivo: ' . $error
            );
        }

        $stmt->bind_result($name);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found) {
            return null;
        }

        $name = trim((string)$name);
        return $name !== '' ? $name : null;
    }
}
