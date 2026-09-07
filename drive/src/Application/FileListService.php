<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use mysqli;
use mysqli_stmt;
use RuntimeException;

final class FileListService
{
    public function __construct(private mysqli $db)
    {
    }

    public function load(int $userId, string $route, array $query): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido para listar archivos.');
        }

        $search = trim((string) ($query['buscar'] ?? ''));
        $type = strtolower(trim((string) ($query['tipo'] ?? '')));
        $dateFrom = trim((string) ($query['fecha_inicio'] ?? ''));
        $dateTo = trim((string) ($query['fecha_fin'] ?? ''));
        $page = max(1, (int) ($query['pagina'] ?? 1));
        $limit = max(5, min(100, (int) ($query['limite'] ?? 10)));

        $where = 'user_id_ = ? AND Ruta = ? AND Found = 1';
        $types = 'is';
        $params = [$userId, $route];

        if ($search !== '') {
            $where .= " AND (Nombre LIKE CONCAT('%',?,'%') OR Encriptado LIKE CONCAT('%',?,'%'))";
            $types .= 'ss';
            $params[] = $search;
            $params[] = $search;
        }
        if ($type !== '') {
            $where .= " AND LOWER(SUBSTRING_INDEX(Nombre,'.',-1)) = ?";
            $types .= 's';
            $params[] = $type;
        }
        if ($dateFrom !== '') {
            $where .= ' AND DATE(Fecha) >= ?';
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where .= ' AND DATE(Fecha) <= ?';
            $types .= 's';
            $params[] = $dateTo;
        }

        // Una sola ida a MySQL para: total filtrado + total/peso de la carpeta.
        $aggregateSql = "SELECT
                (SELECT COUNT(*) FROM FileS3 WHERE {$where}) AS filtered_total,
                COUNT(*) AS folder_total,
                COALESCE(SUM(Tamano), 0) AS folder_bytes
            FROM FileS3
            WHERE user_id_ = ? AND Ruta = ? AND Found = 1";

        $aggregateStmt = $this->prepare($aggregateSql);
        $aggregateParams = array_merge($params, [$userId, $route]);
        $this->bind($aggregateStmt, $types . 'is', $aggregateParams);
        $aggregateStmt->execute();
        $aggregate = $aggregateStmt->get_result()->fetch_assoc() ?: [];
        $aggregateStmt->close();

        $total = (int) ($aggregate['filtered_total'] ?? 0);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        // Segunda y última consulta de navegación: las filas de la página.
        $sql = "SELECT id_, Nombre, Encriptado, Tamano, Metadatos, Ruta,
                       Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt, Fecha, user_id_
                FROM FileS3
                WHERE {$where}
                ORDER BY Fecha DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->prepare($sql);
        $pageParams = array_merge($params, [$limit, $offset]);
        $this->bind($stmt, $types . 'ii', $pageParams);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return [
            'rows' => $rows,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'limit' => $limit,
            'search' => $search,
            'type' => $type,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'folder_total' => (int) ($aggregate['folder_total'] ?? 0),
            'folder_bytes' => (int) ($aggregate['folder_bytes'] ?? 0),
        ];
    }

    public function listExtensions(int $userId, string $route): array
    {
        $stmt = $this->prepare(
            "SELECT DISTINCT LOWER(SUBSTRING_INDEX(Nombre,'.',-1)) AS ext
             FROM FileS3
             WHERE user_id_ = ? AND Ruta = ? AND Found = 1 AND Nombre LIKE '%.%'
             ORDER BY ext"
        );
        $params = [$userId, $route];
        $this->bind($stmt, 'is', $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $extensions = [];
        while ($row = $result->fetch_assoc()) {
            $ext = trim((string) ($row['ext'] ?? ''));
            if ($ext !== '') {
                $extensions[] = $ext;
            }
        }
        $stmt->close();
        return $extensions;
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error SQL prepare: ' . $this->db->error);
        }
        return $stmt;
    }

    private function bind(mysqli_stmt $stmt, string $types, array $params): void
    {
        if ($params === []) {
            return;
        }

        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }
        unset($value);
        $stmt->bind_param($types, ...$refs);
    }
}
