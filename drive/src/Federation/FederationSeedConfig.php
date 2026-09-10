<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use JsonException;

final class FederationSeedConfig
{
    public function __construct(private array $seeds)
    {
    }

    public static function fromProjectConfig(): self
    {
        $override = trim((string)(getenv('ARCADECLOUD_FEDERATION_SEED_URL') ?: ''));
        if ($override !== '') {
            return new self([self::normalizeUrl($override)]);
        }

        $path = dirname(__DIR__, 2) . '/config/federation-seeds.json';
        if (!is_file($path) || !is_readable($path)) {
            throw new FederationException('No se encontró la configuración de seeds FederationCloud.', 500);
        }

        try {
            $decoded = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('La configuración de seeds FederationCloud no es JSON válido.', 500);
        }

        if (!is_array($decoded) || (int)($decoded['version'] ?? 0) !== 1 || !is_array($decoded['seeds'] ?? null)) {
            throw new FederationException('Configuración de seeds FederationCloud inválida.', 500);
        }

        $seeds = [];
        foreach ($decoded['seeds'] as $seed) {
            if (!is_string($seed) || trim($seed) === '') continue;
            $seeds[] = self::normalizeUrl($seed);
        }
        $seeds = array_values(array_unique($seeds));
        if ($seeds === []) {
            throw new FederationException('FederationCloud requiere al menos un seed.', 500);
        }

        return new self($seeds);
    }

    public function primary(): string
    {
        return $this->seeds[0];
    }

    public function all(): array
    {
        return $this->seeds;
    }

    public function isSeed(string $federationUrl): bool
    {
        return in_array(self::normalizeUrl($federationUrl), $this->seeds, true);
    }

    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new FederationException('El seed FederationCloud debe ser una URL HTTPS válida.', 500);
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            throw new FederationException('El seed FederationCloud sólo puede usar HTTPS puerto 443.', 500);
        }
        return rtrim($url, '/') . '/';
    }
}
