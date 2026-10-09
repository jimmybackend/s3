<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\FolderRepository;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use RuntimeException;

/**
 * Resolve Drive 3D backgrounds from visible catalog names, never from S3
 * object keys. ParentPrefix + Nombre is the identity of each visible level.
 */
final class Drive3dBackgroundFolderService
{
    public function __construct(
        private FolderRepository $folders,
        private FolderMutationService $mutations,
        private UserStoragePath $paths
    ) {
    }

    public static function findDirectChild(array $rows, string $parentPrefix, string $visibleName): ?string
    {
        $parent = self::prefix($parentPrefix);
        $found = null;
        foreach ($rows as $row) {
            if (self::prefix((string)($row['ParentPrefix'] ?? '')) !== $parent) continue;
            if (strcasecmp(trim((string)($row['Nombre'] ?? '')), $visibleName) !== 0) continue;
            $candidate = self::prefix((string)($row['Prefix'] ?? ''));
            if ($candidate === '' || !str_starts_with($candidate, $parent) || $candidate === $parent) continue;
            if ($found !== null && $found !== $candidate) {
                throw new RuntimeException('Hay carpetas con el mismo nombre en el mismo nivel. Resuelve la duplicación antes de elegir el fondo.');
            }
            $found = $candidate;
        }
        return $found;
    }

    public function existing(int $userId): ?string
    {
        $root = $this->paths->rootForUser($userId);
        $rows = $this->folders->listHierarchyRows($userId);
        $images = self::findDirectChild($rows, $root, 'Imagenes');
        if ($images === null) return null;
        return self::findDirectChild($rows, $images, 'fondos3D');
    }

    public function ensure(int $userId): string
    {
        $root = $this->paths->rootForUser($userId);
        $rows = $this->folders->listHierarchyRows($userId);
        $images = self::findDirectChild($rows, $root, 'Imagenes');
        if ($images === null) {
            $created = $this->mutations->create($userId, $root, 'Imagenes');
            $images = (string)$created['ruta'];
        }
        $backgrounds = self::findDirectChild($rows, $images, 'fondos3D');
        if ($backgrounds === null) {
            $created = $this->mutations->create($userId, $images, 'fondos3D');
            $backgrounds = (string)$created['ruta'];
        }
        return $this->paths->normalizeForUser($backgrounds, $userId);
    }

    private static function prefix(string $prefix): string
    {
        $prefix = preg_replace('~/+~', '/', str_replace('\\', '/', trim($prefix))) ?? '';
        return $prefix === '' ? '' : rtrim(ltrim($prefix, '/'), '/') . '/';
    }
}
