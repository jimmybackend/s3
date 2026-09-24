<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationDropGoogleAuthConfig
{
    public const ISSUER = 'https://accounts.google.com';
    public const SCOPE = 'openid email profile';

    public function __construct(
        public readonly bool $enabled,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $sessionSecret,
        public readonly int $sessionTtl,
        public readonly string $publicUrl,
        public readonly string $commerceUrl
    ) {
    }

    public static function fromEnvironment(): self
    {
        $drop = FederationDropConfig::fromEnvironment();
        return new self(
            self::envBool('ARCADECLOUD_DROP_GOOGLE_ENABLED', false),
            self::env('ARCADECLOUD_DROP_GOOGLE_CLIENT_ID'),
            self::env('ARCADECLOUD_DROP_GOOGLE_CLIENT_SECRET'),
            self::env('ARCADECLOUD_DROP_GOOGLE_SESSION_SECRET'),
            self::envInt('ARCADECLOUD_DROP_GOOGLE_SESSION_TTL', 28800, 300, 604800),
            rtrim($drop->publicUrl, '/'),
            rtrim($drop->commerceUrl, '/')
        );
    }

    public function ready(): bool
    {
        try {
            $this->assertReady();
            return true;
        } catch (FederationException) {
            return false;
        }
    }

    public function assertReady(): void
    {
        if (!$this->enabled) {
            throw new FederationException('Inicio de sesión con Google está desactivado para FederationDrop.', 503);
        }
        if ($this->clientId === '' || strlen($this->clientId) > 512 || preg_match('/[\x00-\x20\x7F]/', $this->clientId)) {
            throw new FederationException('FederationDrop requiere un Google Client ID válido.', 503);
        }
        if ($this->clientSecret === '' || strlen($this->clientSecret) > 4096 || preg_match('/[\x00-\x20\x7F]/', $this->clientSecret)) {
            throw new FederationException('FederationDrop requiere un Google Client Secret válido.', 503);
        }
        if (strlen($this->sessionSecret) < 32 || preg_match('/[\x00-\x1F\x7F]/', $this->sessionSecret)) {
            throw new FederationException('FederationDrop requiere un secreto de sesión Google de al menos 32 caracteres.', 503);
        }
        if ($this->normalizeUrl($this->publicUrl) !== $this->normalizeUrl($this->commerceUrl)) {
            throw new FederationException('Google FederationDrop sólo puede iniciar sesión en el portal comercial.', 503);
        }
        foreach ([$this->publicUrl, $this->commerceUrl] as $url) {
            $parts = parse_url($url);
            if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new FederationException('Google FederationDrop requiere URLs HTTPS válidas.', 503);
            }
        }
    }

    public function callbackUrl(): string
    {
        return $this->publicUrl . '/google-callback.php';
    }

    public function cookiePath(): string
    {
        $path = (string)(parse_url($this->publicUrl, PHP_URL_PATH) ?: '/federationdrop');
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : $path;
    }

    private function normalizeUrl(string $url): string
    {
        return strtolower(rtrim(trim($url), '/'));
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value === false) return $default;
        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || trim((string)$value) === '') return $default;
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $value = getenv($name);
        if ($value === false || trim((string)$value) === '') return $default;
        if (!preg_match('/\A\d+\z/', trim((string)$value))) return $default;
        return max($min, min($max, (int)$value));
    }
}
