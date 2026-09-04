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
        $limit = max(5, min(100, (int) ($query['limite'] ?? 5)));

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

        $total = $this->count("SELECT COUNT(*) FROM FileS3 WHERE {$where}", $types, $params);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $folderStmt = $this->prepare(
            'SELECT COUNT(*) AS n, COALESCE(SUM(Tamano),0) AS s FROM FileS3 WHERE user_id_ = ? AND Ruta = ? AND Found = 1'
        );
        $folderParams = [$userId, $route];
        $this->bind($folderStmt, 'is', $folderParams);
        $folderStmt->execute();
        $folderRow = $folderStmt->get_result()->fetch_assoc() ?: [];
        $folderStmt->close();

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
            'folder_total' => (int) ($folderRow['n'] ?? 0),
            'folder_bytes' => (int) ($folderRow['s'] ?? 0),
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

    private function count(string $sql, string $types, array $params): int
    {
        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, $params);
        $stmt->execute();
        $stmt->bind_result($value);
        $stmt->fetch();
        $stmt->close();
        return (int) $value;
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
