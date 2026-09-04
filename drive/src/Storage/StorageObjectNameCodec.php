<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use RuntimeException;

final class StorageObjectNameCodec
{
    private const FILE_PREFIX = 'f_';
    private const FOLDER_PREFIX = 'd_';
    private const TOKEN_BYTES = 16;

    public function createFileObjectName(string $visibleName): string
    {
        $visibleName = $this->sanitizeVisibleFileName($visibleName);
        return self::FILE_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES)) . '-' . $visibleName;
    }

    public function createFolderObjectName(string $visibleName): string
    {
        $visibleName = $this->sanitizeVisibleFolderName($visibleName);
        return self::FOLDER_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES)) . '-' . $visibleName;
    }

    public function recoverFileVisibleName(string $objectName): ?string
    {
        $base = basename(str_replace('\\', '/', trim($objectName)));
        if ($base === '') {
            return null;
        }

        // Formato actual: f_<32 hex>-<nombre visible>
        if (preg_match('/^f_[a-f0-9]{32}-(.+)$/iu', $base, $match) === 1) {
            return $this->cleanRecovered($match[1]);
        }

        // Compatibilidad con multipart anterior: <sha1>-<nombre visible>
        if (preg_match('/^[a-f0-9]{40}-(.+)$/iu', $base, $match) === 1) {
            return $this->cleanRecovered($match[1]);
        }

        return null;
    }

    public function recoverFolderVisibleName(string $physicalSegment): ?string
    {
        $segment = trim(str_replace('\\', '/', $physicalSegment), '/');
        $segment = basename($segment);
        if ($segment === '') {
            return null;
        }

        if (preg_match('/^d_[a-f0-9]{32}-(.+)$/iu', $segment, $match) === 1) {
            return $this->cleanRecovered($match[1]);
        }

        return null;
    }

    public function looksLikeOpaqueLegacyFileName(string $objectName): bool
    {
        $base = basename(str_replace('\\', '/', trim($objectName)));
        return preg_match('/^f_[a-z0-9._]+(?:_[a-f0-9]+)?(?:\.[^.]+)?$/i', $base) === 1
            && $this->recoverFileVisibleName($base) === null;
    }

    private function sanitizeVisibleFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('El nombre visible del archivo es inválido.');
        }
        return $this->limit($name, 220);
    }

    private function sanitizeVisibleFolderName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', '_', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '') {
            throw new RuntimeException('El nombre visible de la carpeta es inválido.');
        }
        return $this->limit($name, 180);
    }

    private function cleanRecovered(string $name): ?string
    {
        $name = trim($name);
        return $name === '' ? null : $name;
    }

    private function limit(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }
        return substr($value, 0, $max);
    }
}
