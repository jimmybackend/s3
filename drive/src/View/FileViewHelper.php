<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FileViewHelper
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function formatBytes(int|float $bytes, int $decimals = 2): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        if ($index === 0) {
            return (string) ((int) $bytes) . ' B';
        }

        return number_format($bytes, $decimals, '.', '') . ' ' . $units[$index];
    }

    public static function extension(string $name): string
    {
        return strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    }

    public static function buildS3Key(string $route, string $encryptedName): string
    {
        $route = rtrim(str_replace('\\', '/', trim($route)), '/') . '/';
        $encryptedName = ltrim(str_replace('\\', '/', trim($encryptedName)), '/');

        if ($encryptedName === '') {
            return '';
        }

        if (strpos($encryptedName, $route) === 0) {
            return $encryptedName;
        }

        return $route . $encryptedName;
    }

    public static function isEncrypted(array $row): bool
    {
        return (string) ($row['Nombre'] ?? '') !== (string) ($row['Encriptado'] ?? '');
    }

    public static function isLocked(array $row): bool
    {
        return (string) ($row['AccessType'] ?? 'normal') === 'secure';
    }

    public static function hasSecurity(array $row): bool
    {
        $accessType = (string) ($row['AccessType'] ?? 'normal');
        $passwordHash = (string) ($row['PasswordHash'] ?? '');

        return in_array($accessType, ['secure', 'unlocked'], true) || $passwordHash !== '';
    }

    public static function metadataTooltip(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return 'Sin metadatos';
        }

        $raw = trim($raw);
        $decoded = json_decode($raw, true);
        $pretty = json_last_error() === JSON_ERROR_NONE
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $raw;

        $length = function_exists('mb_strlen') ? mb_strlen($pretty) : strlen($pretty);
        if ($length > 2000) {
            $pretty = function_exists('mb_substr') ? mb_substr($pretty, 0, 2000) : substr($pretty, 0, 2000);
            $pretty .= '…';
        }

        $pretty = htmlspecialchars($pretty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return str_replace(["\r\n", "\r", "\n"], '&#10;', $pretty);
    }
}
