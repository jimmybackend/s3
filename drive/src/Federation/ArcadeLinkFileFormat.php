<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

/**
 * Contrato nativo del archivo ArcadeLink.
 *
 * ArcadeLink usa una serialización interna JSON firmada, pero su identidad
 * externa es un archivo .arcadelink con media type propio de ArcadeCloud.
 */
final class ArcadeLinkFileFormat
{
    public const EXTENSION = '.arcadelink';
    public const MIME_TYPE = 'application/vnd.arcadecloud.arcadelink';
    public const LEGACY_JSON_SUFFIX = '.arcadelink.json';

    public static function acceptsFilename(string $name): bool
    {
        $name = strtolower(trim($name));
        return str_ends_with($name, self::EXTENSION)
            || str_ends_with($name, self::LEGACY_JSON_SUFFIX);
    }

    public static function canonicalFilename(string $filename, string $fallback = 'recurso.arcadelink'): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($filename)) ?: $fallback;
        if (str_ends_with(strtolower($filename), self::LEGACY_JSON_SUFFIX)) {
            $filename = substr($filename, 0, -strlen('.json'));
        }
        if (!str_ends_with(strtolower($filename), self::EXTENSION)) {
            $filename .= self::EXTENSION;
        }
        return $filename;
    }

    public static function acceptsDetectedMime(string $mime): bool
    {
        $mime = strtolower(trim($mime));
        if ($mime === '' || $mime === self::MIME_TYPE) return true;
        if (in_array($mime, ['application/json', 'text/plain', 'application/octet-stream', 'application/x-empty'], true)) {
            return true;
        }
        return str_ends_with($mime, '+json');
    }
}
