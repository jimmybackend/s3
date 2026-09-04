<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use mysqli;
use RuntimeException;

final class FolderTreeRenderer
{
    private array $children = [];
    private bool $loaded = false;

    public function __construct(
        private mysqli $db,
        private int $userId,
        private UserStoragePath $paths
    ) {
    }

    public function root(): string
    {
        return $this->paths->rootForUser($this->userId);
    }

    public function normalize(string $prefix): string
    {
        return $this->paths->normalizeForUser($prefix, $this->userId);
    }

    public function hasChildren(string $parent): bool
    {
        $this->load();
        $parent = $this->normalize($parent);
        return !empty($this->children[$parent]);
    }

    public function renderChildren(string $parent, string $active): string
    {
        $this->load();
        $parent = $this->normalize($parent);
        $active = $this->normalize($active);
        $rows = $this->children[$parent] ?? [];
        if ($rows === []) {
            return '';
        }

        $html = '<ul class="subfolders list-unstyled mb-0" data-parent="' . self::e($parent) . '">';
        foreach ($rows as $row) {
            $prefix = $row['prefix'];
            $name = $row['name'];
            $isActive = $prefix === $active;
            $isAncestor = strpos($active, $prefix) === 0 && $prefix !== $active;
            $hasKids = !empty($this->children[$prefix]);
            $display = ($isAncestor || $isActive) ? 'block' : 'none';

            $html .= '<li class="folder-item" data-prefix="' . self::e($prefix) . '">';
            $html .= '<div class="folder-row d-flex align-items-center">';
            $html .= '<span class="' . ($hasKids ? 'toggle' : 'toggle empty') . '" title="Expandir/contraer">' . ($hasKids ? '−' : '·') . '</span>';
            $html .= '<a href="#" class="folder' . ($isActive ? ' active' : '') . '" data-route="' . self::e($prefix) . '" data-ruta="' . self::e($prefix) . '">';
            $html .= '<i class="fas fa-folder mr-1"></i> <span class="name">' . self::e($name) . '</span></a>';
            $html .= '<div class="ml-auto btn-group btn-group-sm">';
            $html .= '<button type="button" class="btn btn-light btn-xs" title="Mover" data-toggle="modal" data-target="#modalMoverCarpeta" data-route="' . self::e($prefix) . '" data-name="' . self::e($name) . '"><i class="fas fa-arrows-alt"></i></button>';
            $html .= '<button type="button" class="btn btn-light btn-xs" title="Renombrar" data-toggle="modal" data-target="#modalRenombrar" data-actual="' . self::e($prefix) . '" data-nombre="' . self::e($name) . '"><i class="fas fa-i-cursor"></i></button>';
            $html .= '<button type="button" class="btn btn-light btn-xs text-danger" title="Eliminar" data-toggle="modal" data-target="#modalEliminarCarpeta" data-route="' . self::e($prefix) . '" data-name="' . self::e($name) . '"><i class="fas fa-trash"></i></button>';
            $html .= '</div></div>';
            $html .= '<div class="children" style="display:' . $display . '">';
            if ($hasKids) {
                $html .= $this->renderChildren($prefix, $active);
            }
            $html .= '</div></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $stmt = $this->db->prepare(
            'SELECT Prefix, ParentPrefix, Nombre FROM S3Folders WHERE user_id_ = ? AND Found = 1 ORDER BY Nombre ASC'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo cargar el árbol de carpetas: ' . $this->db->error);
        }

        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $root = $this->root();

        while ($row = $result->fetch_assoc()) {
            $prefix = $this->normalize((string) ($row['Prefix'] ?? ''));
            if ($prefix === $root) {
                continue;
            }

            $parentRaw = trim((string) ($row['ParentPrefix'] ?? ''));
            $parent = $parentRaw === '' ? $root : $this->normalize($parentRaw);
            $name = trim((string) ($row['Nombre'] ?? ''));
            if ($name === '') {
                $name = basename(rtrim($prefix, '/'));
            }

            $this->children[$parent][] = [
                'prefix' => $prefix,
                'name' => $name,
            ];
        }
        $stmt->close();
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
