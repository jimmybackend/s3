<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationDropConfig
{
    public const DEFAULT_COMMERCE_URL = 'https://drive.esforzados.com/federationdrop';

    public function __construct(
        public readonly bool $enabled,
        public readonly string $publicUrl,
        public readonly string $commerceUrl,
        public readonly string $stripeSecretKey,
        public readonly string $stripeWebhookSecret,
        public readonly string $currency,
        public readonly int $baseFeeCents,
        public readonly int $storageGbDayCents,
        public readonly int $egressGbCents,
        public readonly int $pendingHours,
        public readonly int $maxDays,
        public readonly int $maxDownloads,
        public readonly int $maxFileBytes
    ) {
    }

    public static function fromEnvironment(): self
    {
        $base = rtrim(self::env('ARCADECLOUD_PUBLIC_URL'), '/');
        $publicUrl = rtrim(self::env('ARCADECLOUD_DROP_PUBLIC_URL', $base !== '' ? $base . '/federationdrop' : ''), '/');
        $commerceUrl = rtrim(self::env('ARCADECLOUD_DROP_COMMERCE_URL', self::DEFAULT_COMMERCE_URL), '/');

        return new self(
            self::envBool('ARCADECLOUD_DROP_ENABLED', false),
            $publicUrl,
            $commerceUrl,
            self::env('ARCADECLOUD_STRIPE_SECRET_KEY'),
            self::env('ARCADECLOUD_STRIPE_WEBHOOK_SECRET'),
            strtoupper(self::env('ARCADECLOUD_DROP_CURRENCY', 'MXN')),
            self::envInt('ARCADECLOUD_DROP_BASE_FEE_CENTS', 0, 0, 100000000),
            self::envInt('ARCADECLOUD_DROP_STORAGE_GB_DAY_CENTS', 0, 0, 100000000),
            self::envInt('ARCADECLOUD_DROP_EGRESS_GB_CENTS', 0, 0, 100000000),
            self::envInt('ARCADECLOUD_DROP_PENDING_HOURS', 2, 1, 72),
            self::envInt('ARCADECLOUD_DROP_MAX_DAYS', 30, 1, 3650),
            self::envInt('ARCADECLOUD_DROP_MAX_DOWNLOADS', 1000, 1, 1000000),
            self::envInt('ARCADECLOUD_DROP_MAX_FILE_BYTES', 5368709120, 1048576, 5368709120)
        );
    }

    public function isCommerceNode(): bool
    {
        return $this->normalizeUrl($this->publicUrl) !== ''
            && hash_equals($this->normalizeUrl($this->commerceUrl), $this->normalizeUrl($this->publicUrl));
    }

    public function assertReady(): void
    {
        if (!$this->enabled) {
            throw new FederationException('FederationDrop comercial está desactivado en este nodo.', 503);
        }
        if (!$this->isCommerceNode()) {
            throw new FederationException('Este nodo no es el portal comercial FederationDrop. El cobro y la custodia pertenecen al nodo comercial configurado.', 503);
        }

        foreach ([$this->publicUrl, $this->commerceUrl] as $url) {
            $parts = parse_url($url);
            if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new FederationException('FederationDrop requiere URLs HTTPS válidas sin credenciales, query ni fragmento.', 503);
            }
        }

        $this->assertStripeReady();

        if (!preg_match('/\A[A-Z]{3}\z/', $this->currency)) {
            throw new FederationException('Moneda FederationDrop inválida.', 503);
        }
        if ($this->baseFeeCents + $this->storageGbDayCents + $this->egressGbCents <= 0) {
            throw new FederationException('Configura una tarifa positiva para FederationDrop.', 503);
        }
    }

    public function assertStripeReady(): void
    {
        if (!preg_match('/^(?:sk|rk)_(?:test|live)_[A-Za-z0-9_]+$/', $this->stripeSecretKey)) {
            throw new FederationException('FederationDrop requiere una clave secreta Stripe válida.', 503);
        }
        if (!str_starts_with($this->stripeWebhookSecret, 'whsec_') || strlen($this->stripeWebhookSecret) < 16) {
            throw new FederationException('FederationDrop requiere un secreto webhook Stripe válido.', 503);
        }
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
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
