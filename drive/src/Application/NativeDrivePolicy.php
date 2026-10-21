<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use ArcadeCloud\Drive\View\FileViewHelper;
use RuntimeException;

/** Native DTO boundary: never serialize authentication hashes or arbitrary DB rows. */
final class NativeDrivePolicy
{
    public static function route(UserStoragePath $paths, int $userId, string $input): string
    {
        if ($userId <= 0) throw new RuntimeException('Sesión inválida.', 401);
        $root = $paths->rootForUser($userId);
        if ($input === '') return $root;
        if (!str_starts_with($input, $root) || str_contains($input, '\\')
            || preg_match('~(^|/)\.{1,2}(/|$)|//|[\x00-\x1f]~', $input)) {
            throw new RuntimeException('Ruta no autorizada.', 403);
        }
        return rtrim($input, '/') . '/';
    }

    public static function file(array $row): array
    {
        $locked = FileViewHelper::isLocked($row);
        return [
            'id' => (int)$row['id_'], 'name' => (string)$row['Nombre'],
            'key' => FileViewHelper::buildS3Key((string)$row['Ruta'], (string)$row['Encriptado']),
            'route' => (string)$row['Ruta'], 'bytes' => (int)$row['Tamano'],
            'date' => (string)$row['Fecha'], 'locked' => $locked,
            'hint' => (string)($row['SecureHint'] ?? ''),
            'metadata' => $locked ? null : FileViewHelper::metadataArray($row['Metadatos'] ?? null),
        ];
    }
}
