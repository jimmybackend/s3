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
     * Destinos para selects de mover.
     *
     * value conserva el Prefix físico necesario para ejecutar la operación.
     * label se construye únicamente con Nombre de S3Folders y es lo único que
     * debe mostrarse al usuario.
     */
    public function destinationsForUser(int $userId, bool $includeBase = true): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $root = $this->normalizePrefix($this->paths->rootForUser($userId));
        $rows = $this->repository->listHierarchyRows($userId);
        $prefixes = $this->allForUser($userId, $includeBase);
        $destinations = [];

        foreach ($prefixes as $prefix) {
            $prefix = $this->normalizePrefix((string)$prefix);
            $destinations[] = [
                'value' => $prefix,
                'label' => $this->displayPathFromRows($root, $prefix, $rows),
            ];
        }

        usort($destinations, static function (array $a, array $b): int {
            return strnatcasecmp((string)$a['label'], (string)$b['label']);
        });

        return $destinations;
    }

    /**
     * Ruta legible para interfaz construida exclusivamente con S3Folders.Nombre.
     */
    public function displayPathForUser(int $userId, string $prefix): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $root = $this->normalizePrefix($this->paths->rootForUser($userId));
        $target = $this->normalizePrefix($this->paths->normalizeForUser($prefix, $userId));
        $rows = $this->repository->listHierarchyRows($userId);

        return $this->displayPathFromRows($root, $target, $rows);
    }

    /**
     * Breadcrumbs para interfaz: label es el nombre de catálogo visible y
     * route conserva el Prefix físico usado exclusivamente por el backend.
     *
     * @return array<int,array{label:string,route:string}>
     */
    public function breadcrumbsForUser(int $userId, string $prefix): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $root = $this->normalizePrefix($this->paths->rootForUser($userId));
        $target = $this->normalizePrefix($this->paths->normalizeForUser($prefix, $userId));
        $rows = $this->repository->listHierarchyRows($userId);
        $byPrefix = [];
        foreach ($rows as $row) {
            $rowPrefix = $this->normalizePrefix((string)($row['Prefix'] ?? ''));
            if ($rowPrefix === '') {
                continue;
            }
            $byPrefix[$rowPrefix] = [
                'label' => trim((string)($row['Nombre'] ?? '')),
                'parent' => $this->normalizePrefix((string)($row['ParentPrefix'] ?? '')),
            ];
        }

        $chain = [];
        $cursor = $target;
        $guard = 0;
        while ($cursor !== '' && $guard++ < 256) {
            $row = $byPrefix[$cursor] ?? null;
            $label = trim((string)($row['label'] ?? ''));
            if ($cursor === $root && $label === '') {
                $label = 'Mi Drive';
            }
            if ($label !== '') {
                $chain[] = ['label' => $label, 'route' => $cursor];
            }
            if ($cursor === $root) {
                break;
            }
            $parent = $this->normalizePrefix((string)($row['parent'] ?? ''));
            if ($parent === '' || $parent === $cursor || strpos($parent, $root) !== 0) {
                break;
            }
            $cursor = $parent;
        }

        $chain = array_reverse($chain);
        if ($chain === [] || (string)($chain[0]['route'] ?? '') !== $root) {
            array_unshift($chain, ['label' => 'Mi Drive', 'route' => $root]);
        }
        return $chain;
    }

    private function displayPathFromRows(string $root, string $target, array $rows): string
    {
        $segments = [];
        $rootName = basename(rtrim($root, '/')) ?: 'Inicio';

        foreach ($rows as $row) {
            $rowPrefix = $this->normalizePrefix((string)($row['Prefix'] ?? ''));
            if ($rowPrefix === '') {
                continue;
            }

            if ($rowPrefix !== $root && strpos($target, $rowPrefix) !== 0) {
                continue;
            }

            $name = trim((string)($row['Nombre'] ?? ''));
            if ($name === '') {
                continue;
            }

            if ($rowPrefix === $root) {
                $rootName = $name;
            }

            $segments[$rowPrefix] = $name;
        }

        if (!isset($segments[$root])) {
            $segments[$root] = $rootName;
        }

        uksort($segments, static fn(string $a, string $b): int => strlen($a) <=> strlen($b));

        $visible = [];
        foreach ($segments as $rowPrefix => $name) {
            if ($rowPrefix === $root || strpos($target, $rowPrefix) === 0) {
                $name = trim((string)$name);
                if ($name !== '') {
                    $visible[] = $name;
                }
            }
        }

        if ($visible === []) {
            $visible[] = $rootName;
        }

        return implode('/', $visible) . '/';
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = str_replace('\\', '/', trim($prefix));
        $prefix = preg_replace('~/+~', '/', $prefix) ?? $prefix;
        $prefix = ltrim($prefix, '/');
        return rtrim($prefix, '/') . '/';
    }
}
