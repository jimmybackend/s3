<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationConfig
{
    public function __construct(
        private string $publicUrl,
        private string $federationUrl,
        private string $identityPath,
        private bool $enabled
    ) {
    }

    public static function fromEnvironment(): self
    {
        $publicUrl = self::requiredUrl('ARCADECLOUD_PUBLIC_URL');
        $federationUrl = self::requiredUrl('ARCADECLOUD_FEDERATION_URL');
        if (strtolower((string)parse_url($federationUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new FederationException('ARCADECLOUD_FEDERATION_URL debe usar HTTPS.', 500);
        }
        $identityPath = trim((string)(getenv('ARCADECLOUD_FEDERATION_IDENTITY') ?: ''));
        if ($identityPath === '') {
            $identityPath = '/etc/arcadecloud-drive/federation-node.json';
        }
        if ($identityPath[0] !== '/') {
            throw new FederationException('ARCADECLOUD_FEDERATION_IDENTITY debe ser una ruta absoluta.', 500);
        }

        return new self(
            rtrim($publicUrl, '/'),
            rtrim($federationUrl, '/') . '/',
            $identityPath,
            self::envBool('ARCADECLOUD_FEDERATION_ENABLED', false)
        );
    }

    public function publicUrl(): string
    {
        return $this->publicUrl;
    }

    public function federationUrl(): string
    {
        return $this->federationUrl;
    }

    public function identityPath(): string
    {
        return $this->identityPath;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    private static function requiredUrl(string $name): string
    {
        $value = trim((string)(getenv($name) ?: ''));
        if ($value === '') {
            throw new FederationException('Falta configurar ' . $name . '.', 503);
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new FederationException($name . ' no contiene una URL válida.', 500);
        }
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new FederationException($name . ' debe usar HTTP o HTTPS.', 500);
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new FederationException($name . ' no debe contener credenciales, query ni fragmento.', 500);
        }
        return $value;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if (!is_string($raw) || trim($raw) === '') return $default;
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) return false;
        throw new FederationException($name . ' debe ser true/false.', 500);
    }
}
