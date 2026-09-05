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

    private function normalizePrefix(string $prefix): string
    {
        $prefix = str_replace('\\', '/', trim($prefix));
        $prefix = preg_replace('~/+~', '/', $prefix) ?? $prefix;
        $prefix = ltrim($prefix, '/');
        return rtrim($prefix, '/') . '/';
    }
}
