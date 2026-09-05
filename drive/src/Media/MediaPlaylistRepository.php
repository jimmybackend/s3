<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use mysqli;
use RuntimeException;

final class MediaPlaylistRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function listForRoute(int $userId, string $route): array
    {
        $sql = "
            SELECT Nombre, Encriptado, Ruta, AccessType, Fecha
            FROM FileS3
            WHERE user_id_ = ?
              AND Ruta = ?
              AND Found = 1
              AND AccessType <> 'secure'
              AND LOWER(SUBSTRING_INDEX(Nombre, '.', -1)) IN (
                'mp3','wav','ogg','opus','m4a','aac','flac','amr',
                'mp4','webm','mov','avi','mkv','m4v','ogv'
              )
            ORDER BY Fecha DESC, id_ DESC
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la playlist.');
        }

        $stmt->bind_param('is', $userId, $route);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
        return $rows;
    }
}
