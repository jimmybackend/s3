<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\View\FileViewHelper;
use mysqli;
use RuntimeException;

final class FileSearchService
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * Busca exclusivamente en FileS3. Nunca recorre S3.
     *
     * Reglas:
     * - factura   -> contiene "factura"
     * - fact*     -> empieza por "fact"
     * - *.pdf     -> termina en ".pdf"
     * - *2026*    -> contiene "2026"
     * - ?         -> un solo carácter
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(int $userId, string $term, int $limit = 200): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido para búsqueda.');
        }

        $term = trim($term);
        if ($term === '') {
            throw new RuntimeException('Debes indicar un nombre o patrón de búsqueda.');
        }

        $limit = max(1, min(500, $limit));
        $pattern = $this->toLikePattern($term);

        $sql = "SELECT id_, Nombre, Encriptado, Tamano, Ruta, Fecha, AccessType, PasswordHash
                FROM FileS3
                WHERE user_id_ = ?
                  AND Found = 1
                  AND Nombre LIKE ? ESCAPE '='
                ORDER BY
                  CASE WHEN Nombre = ? THEN 0 ELSE 1 END,
                  Fecha DESC,
                  Nombre ASC
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la búsqueda: ' . $this->db->error);
        }

        $stmt->bind_param('issi', $userId, $pattern, $term, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $route = (string)($row['Ruta'] ?? '');
            $encrypted = (string)($row['Encriptado'] ?? '');
            $name = (string)($row['Nombre'] ?? '');
            $bytes = max(0, (int)($row['Tamano'] ?? 0));
            $key = FileViewHelper::buildS3Key($route, $encrypted);

            $rows[] = [
                'id' => (int)($row['id_'] ?? 0),
                'key' => $key,
                'nombre' => $encrypted !== '' ? basename($encrypted) : $name,
                'nombre_real' => $name,
                'ruta' => $route,
                'tamano_kb' => round($bytes / 1024, 2),
                'tamano' => $bytes,
                'tamano_formateado' => FileViewHelper::formatBytes($bytes),
                'fecha' => (string)($row['Fecha'] ?? ''),
                'bloqueado' => FileViewHelper::isLocked($row),
            ];
        }
        $stmt->close();

        return $rows;
    }

    private function toLikePattern(string $term): string
    {
        $hasWildcard = str_contains($term, '*') || str_contains($term, '?');

        // ESCAPE '=': preserva %, _, = como caracteres literales.
        $escaped = str_replace(
            ['=', '%', '_'],
            ['==', '=%', '=_'],
            $term
        );

        $escaped = str_replace(['*', '?'], ['%', '_'], $escaped);

        return $hasWildcard ? $escaped : '%' . $escaped . '%';
    }
}
