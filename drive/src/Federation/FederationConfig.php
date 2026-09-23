<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationConfig
{
    public function __construct(
        private string $publicUrl,
        private string $federationUrl,
        private string $identityPath,
        private bool $enabled,
        private ?string $replicaOriginUrl = null
    ) {
    }

    public static function fromEnvironment(): self
    {
        $publicUrl = self::requiredUrl('ARCADECLOUD_PUBLIC_URL');
        $federationUrl = self::requiredUrl('ARCADECLOUD_FEDERATION_URL');
        self::assertFederationTransport($publicUrl, $federationUrl);
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
            self::envBool('ARCADECLOUD_FEDERATION_ENABLED', false),
            self::optionalHttpsUrl('ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL')
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

    public function replicaOriginUrl(): ?string
    {
        return $this->replicaOriginUrl;
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

    private static function assertFederationTransport(string $publicUrl, string $federationUrl): void
    {
        $federation = parse_url($federationUrl);
        $public = parse_url($publicUrl);
        $scheme = strtolower((string)($federation['scheme'] ?? ''));
        $host = (string)($federation['host'] ?? '');

        if ($scheme === 'https') return;

        $publicScheme = strtolower((string)($public['scheme'] ?? ''));
        $publicHost = (string)($public['host'] ?? '');
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($scheme !== 'http' || !$isIp || $publicScheme !== 'http' || !hash_equals($publicHost, $host)) {
            throw new FederationException(
                'FederationCloud sólo admite HTTPS en dominios; HTTP se permite únicamente para la IP literal del nodo.',
                500
            );
        }
    }

    private static function optionalHttpsUrl(string $name): ?string
    {
        $value = trim((string)(getenv($name) ?: ''));
        if ($value === '') return null;
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])) {
            throw new FederationException($name . ' debe contener una URL HTTPS válida.', 500);
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new FederationException($name . ' no debe contener credenciales, query ni fragmento.', 500);
        }
        return rtrim($value, '/') . '/';
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
