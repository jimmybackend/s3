<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

/**
 * Contrato nativo del archivo ArcadeLink.
 *
 * La serialización interna firmada es un detalle del protocolo; externamente
 * sólo existe el archivo .arcadelink con media type propio de ArcadeCloud.
 */
final class ArcadeLinkFileFormat
{
    public const EXTENSION = '.arcadelink';
    public const MIME_TYPE = 'application/vnd.arcadecloud.arcadelink';

    public static function acceptsFilename(string $name): bool
    {
        return str_ends_with(strtolower(trim($name)), self::EXTENSION);
    }

    public static function canonicalFilename(string $filename, string $fallback = 'recurso.arcadelink'): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($filename)) ?: $fallback;
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
