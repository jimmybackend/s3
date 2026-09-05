<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use Aws\S3\S3Client;
use DateTimeInterface;
use RuntimeException;

final class PublicSharedBrowserService
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $basePrefix,
        private PublicSharedBrowserRepository $repository
    ) {
        $this->basePrefix = $this->normalizeBasePrefix($this->basePrefix);
    }

    public function browse(string $requestedRoute): array
    {
        $route = $this->normalizeRoute($requestedRoute);
        $prefix = $this->prefixForRoute($route);

        $result = $this->s3->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
            'Delimiter' => '/',
        ]);

        $folders = [];
        foreach ($result['CommonPrefixes'] ?? [] as $row) {
            $folderPrefix = trim((string)($row['Prefix'] ?? ''));
            $name = basename(rtrim($folderPrefix, '/'));
            if ($name !== '') {
                $folders[] = $name;
            }
        }

        $files = [];
        foreach ($result['Contents'] ?? [] as $object) {
            $key = trim((string)($object['Key'] ?? ''));
            if ($key === '' || str_ends_with($key, '/')) {
                continue;
            }

            $physicalName = basename($key);
            $visibleName = $this->repository->visibleName($physicalName, $prefix) ?? $physicalName;
            $lastModified = $object['LastModified'] ?? null;

            if ($lastModified instanceof DateTimeInterface) {
                $modified = $lastModified->format('Y-m-d H:i:s');
            } else {
                $timestamp = strtotime((string)$lastModified);
                $modified = $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : '';
            }

            $size = max(0, (int)($object['Size'] ?? 0));

            $files[] = [
                'key' => $key,
                'physical_name' => $physicalName,
                'visible_name' => $visibleName,
                'modified' => $modified,
                'size_bytes' => $size,
                'size_mb' => round($size / 1024 / 1024, 2),
            ];
        }

        return [
            'route' => $route,
            'prefix' => $prefix,
            'parent_route' => $this->parentRoute($route),
            'folders' => $folders,
            'files' => $files,
        ];
    }

    public function createFolder(string $requestedRoute, string $requestedName): string
    {
        $route = $this->normalizeRoute($requestedRoute);
        $name = $this->normalizeFolderName($requestedName);
        $key = $this->prefixForRoute($route) . $name . '/';

        $this->assertWithinSharedRoot($key);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => '',
            'ContentType' => 'application/x-directory',
            'ACL' => 'private',
        ]);

        return $route;
    }

    private function normalizeBasePrefix(string $prefix): string
    {
        $prefix = str_replace('\\', '/', trim($prefix));
        $prefix = preg_replace('~/+~', '/', $prefix) ?? $prefix;
        $prefix = trim($prefix, '/');

        if ($prefix === '') {
            throw new RuntimeException('La raíz compartida no está configurada.');
        }

        return $prefix . '/';
    }

    private function normalizeRoute(string $route): string
    {
        $route = str_replace('\\', '/', trim($route));
        $route = preg_replace('~/+~', '/', $route) ?? $route;
        $route = trim($route, '/');

        if ($route === '') {
            return '';
        }

        $segments = explode('/', $route);
        $clean = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Ruta compartida inválida.');
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $segment)) {
                throw new RuntimeException('Ruta compartida inválida.');
            }
            $clean[] = $segment;
        }

        $normalized = implode('/', $clean);
        $this->assertWithinSharedRoot($this->prefixForRoute($normalized));
        return $normalized;
    }

    private function normalizeFolderName(string $name): string
    {
        $name = trim($name);

        if (
            $name === '' ||
            $name === '.' ||
            $name === '..' ||
            strpbrk($name, '\\/:*?"<>|') !== false ||
            preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            throw new RuntimeException('El nombre de la carpeta contiene caracteres no permitidos.');
        }

        return $name;
    }

    private function prefixForRoute(string $route): string
    {
        if ($route === '') {
            return $this->basePrefix;
        }

        return $this->basePrefix . trim($route, '/') . '/';
    }

    private function parentRoute(string $route): string
    {
        if ($route === '') {
            return '';
        }

        $parts = explode('/', $route);
        array_pop($parts);
        return implode('/', $parts);
    }

    private function assertWithinSharedRoot(string $prefix): void
    {
        $normalized = str_replace('\\', '/', $prefix);
        $normalized = preg_replace('~/+~', '/', $normalized) ?? $normalized;
        $normalized = ltrim($normalized, '/');

        if (strpos($normalized, $this->basePrefix) !== 0 || str_contains($normalized, '..')) {
            throw new RuntimeException('La ruta solicitada está fuera de la zona compartida.');
        }
    }
}
