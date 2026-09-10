<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationCodec
{
    private function __construct()
    {
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        if ($value === '' || !preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            throw new FederationException('Valor Base64URL inválido.');
        }
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new FederationException('Valor Base64URL inválido.');
        }
        return $decoded;
    }

    public static function canonicalJson(array $value): string
    {
        $normalized = self::sortRecursive($value);
        return json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sortRecursive'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }
        return $value;
    }
}
