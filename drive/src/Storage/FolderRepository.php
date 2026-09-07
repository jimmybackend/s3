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

    /**
     * Filas activas del árbol del usuario.
     *
     * Prefix/ParentPrefix siguen siendo identificadores físicos internos;
     * Nombre es la etiqueta visible que debe mostrarse al usuario.
     */
    public function listHierarchyRows(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT Prefix, ParentPrefix, Nombre
             FROM S3Folders
             WHERE user_id_ = ?
               AND Found = 1
             ORDER BY LENGTH(Prefix) ASC, Prefix ASC"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo preparar la jerarquía de carpetas: ' . $this->db->error
            );
        }

        $stmt->bind_param('i', $userId);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException(
                'No se pudo consultar la jerarquía de carpetas: ' . $error
            );
        }

        $result = $stmt->get_result();
        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $prefix = trim((string)($row['Prefix'] ?? ''));
            if ($prefix === '') {
                continue;
            }

            $rows[] = [
                'Prefix' => $prefix,
                'ParentPrefix' => isset($row['ParentPrefix']) ? (string)$row['ParentPrefix'] : null,
                'Nombre' => trim((string)($row['Nombre'] ?? '')),
            ];
        }

        $stmt->close();
        return $rows;
    }
}
