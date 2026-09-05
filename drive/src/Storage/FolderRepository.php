<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use mysqli;
use RuntimeException;

final class FolderRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function listPrefixesUnder(int $userId, string $basePrefix): array
    {
        $stmt = $this->db->prepare(
            "SELECT Prefix
             FROM S3Folders
             WHERE user_id_ = ?
               AND Found = 1
               AND (Prefix = ? OR Prefix LIKE CONCAT(?, '%'))
             ORDER BY Prefix ASC"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo preparar la consulta de carpetas: ' . $this->db->error
            );
        }

        $stmt->bind_param('iss', $userId, $basePrefix, $basePrefix);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException(
                'No se pudo consultar las carpetas: ' . $error
            );
        }

        $result = $stmt->get_result();
        $prefixes = [];

        while ($row = $result->fetch_assoc()) {
            $prefix = trim((string)($row['Prefix'] ?? ''));
            if ($prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        $stmt->close();
        return $prefixes;
    }
}
