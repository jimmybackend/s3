<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\FolderRepository;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use RuntimeException;

final class FolderQueryService
{
    public function __construct(
        private FolderRepository $repository,
        private UserStoragePath $paths
    ) {
    }

    public function allForUser(int $userId, bool $includeBase = true): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $basePrefix = $this->paths->rootForUser($userId);
        $prefixes = $this->repository->listPrefixesUnder($userId, $basePrefix);
        $folders = [];

        foreach ($prefixes as $prefix) {
            $prefix = $this->normalizePrefix((string)$prefix);
            if (strpos($prefix, $basePrefix) !== 0) {
                continue;
            }
            $folders[$prefix] = true;
        }

        if ($includeBase) {
            $folders[$basePrefix] = true;
        }

        $list = array_keys($folders);
        natcasesort($list);
        return array_values($list);
    }

    /**
     * Ruta legible para interfaz construida exclusivamente con S3Folders.Nombre.
     *
     * El Prefix físico sigue siendo el identificador operativo interno, pero no
     * se expone al usuario cuando existe la jerarquía correspondiente en MySQL.
     */
    public function displayPathForUser(int $userId, string $prefix): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $root = $this->normalizePrefix($this->paths->rootForUser($userId));
        $target = $this->paths->normalizeForUser($prefix, $userId);
        $target = $this->normalizePrefix($target);

        $rows = $this->repository->listHierarchyRows($userId);
        $segments = [];
        $seen = [];
        $rootName = basename(rtrim($root, '/')) ?: 'Inicio';

        foreach ($rows as $row) {
            $rowPrefix = $this->normalizePrefix((string)($row['Prefix'] ?? ''));
            if ($rowPrefix === '') {
                continue;
            }

            if ($rowPrefix !== $root && strpos($target, $rowPrefix) !== 0) {
                continue;
            }

            if ($rowPrefix === $root || strpos($target, $rowPrefix) === 0) {
                $name = trim((string)($row['Nombre'] ?? ''));
                if ($name === '') {
                    continue;
                }

                if ($rowPrefix === $root) {
                    $rootName = $name;
                }

                $segments[$rowPrefix] = $name;
            }
        }

        if (!isset($segments[$root])) {
            $segments[$root] = $rootName;
        }

        uksort($segments, static fn(string $a, string $b): int => strlen($a) <=> strlen($b));

        foreach ($segments as $rowPrefix => $name) {
            if ($rowPrefix === $root || strpos($target, $rowPrefix) === 0) {
                $seen[] = $name;
            }
        }

        $seen = array_values(array_filter(
            array_map(static fn(string $name): string => trim($name), $seen),
            static fn(string $name): bool => $name !== ''
        ));

        if ($seen === []) {
            $seen[] = $rootName;
        }

        return implode('/', $seen) . '/';
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = str_replace('\\', '/', trim($prefix));
        $prefix = preg_replace('~/+~', '/', $prefix) ?? $prefix;
        $prefix = ltrim($prefix, '/');
        return rtrim($prefix, '/') . '/';
    }
}
