<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Integration;

use mysqli;
use RuntimeException;

final class LegalCatalogRepository
{
    public function __construct(private mysqli $db) {}
    private function rows(string $sql, string $types, array $params): array
    {
        $s = $this->db->prepare($sql);
        if (!$s) throw new RuntimeException('Catálogo no disponible.', 503);
        $s->bind_param($types, ...$params);
        $s->execute(); $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        return $rows;
    }
    public function folder(int $user, int $id): array
    {
        $r=$this->rows('SELECT id_, Prefix, ParentPrefix, Nombre, AccessType FROM S3Folders WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1','ii',[$id,$user]);
        if (!$r) throw new RuntimeException('Carpeta no disponible.',404);
        return $r[0];
    }
    public function parentId(int $user, ?array $folder, string $root): int
    {
        if (!$folder || $folder['Prefix']===$root || $folder['ParentPrefix']===$root) return 0;
        $rows=$this->rows('SELECT id_ FROM S3Folders WHERE user_id_=? AND Prefix=? AND Found=1 LIMIT 1','is',[$user,(string)$folder['ParentPrefix']]);
        return (int)($rows[0]['id_']??0);
    }
    public function children(int $user, string $route, int $offset): array
    {
        return $this->rows('SELECT id_, Nombre, AccessType FROM S3Folders WHERE user_id_=? AND ParentPrefix=? AND Found=1 ORDER BY Nombre,id_ LIMIT 31 OFFSET ?','isi',[$user,$route,$offset]);
    }
    public function files(int $user, string $route, string $search, int $offset): array
    {
        if ($search !== '') {
            // LOCATE treats %, _ and backslash as literal search text.
            return $this->rows('SELECT * FROM FileS3 WHERE user_id_=? AND Found=1 AND LOCATE(?,Nombre)>0 ORDER BY Nombre,id_ LIMIT 31 OFFSET ?','isi',[$user,$search,$offset]);
        }
        return $this->rows('SELECT * FROM FileS3 WHERE user_id_=? AND Found=1 AND Ruta=? ORDER BY Nombre,id_ LIMIT 31 OFFSET ?','isi',[$user,$route,$offset]);
    }
    /** Missing catalog ancestors fail closed; locked folders cannot be bypassed by global search. */
    public function requireReadableRoute(int $user, string $route, string $root): void
    {
        if (!str_starts_with($route,$root) || str_contains($route,'..') || str_contains($route,'\\')) throw new RuntimeException('Ubicación no autorizada.',403);
        $seen=[];
        while (true) {
            if (isset($seen[$route]) || count($seen)>256) throw new RuntimeException('Ubicación no disponible.',404);
            $seen[$route]=true;
            $r=$this->rows('SELECT ParentPrefix, AccessType FROM S3Folders WHERE user_id_=? AND Prefix=? AND Found=1 LIMIT 1','is',[$user,$route]);
            if (!$r) throw new RuntimeException('Ubicación no disponible.',404);
            if (($r[0]['AccessType'] ?? 'normal') === 'secure') throw new RuntimeException('Ubicación protegida.',423);
            if ($route === $root) return;
            $route=(string)$r[0]['ParentPrefix'];
            if (!str_starts_with($route,$root)) throw new RuntimeException('Ubicación no autorizada.',403);
        }
    }
}
