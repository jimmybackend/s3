<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationDropConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $publicUrl,
        public readonly string $checkoutUrl,
        public readonly string $webhookSecret,
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
        $publicUrl = self::env('ARCADECLOUD_DROP_PUBLIC_URL', $base !== '' ? $base . '/federationdrop' : '');
        return new self(
            self::envBool('ARCADECLOUD_DROP_ENABLED', false),
            rtrim($publicUrl, '/'),
            self::env('ARCADECLOUD_DROP_CHECKOUT_URL'),
            self::env('ARCADECLOUD_DROP_WEBHOOK_SECRET'),
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

    public function assertReady(): void
    {
        if (!$this->enabled) {
            throw new FederationException('FederationDrop está desactivado en este nodo.', 503);
        }
        foreach ([$this->publicUrl, $this->checkoutUrl] as $url) {
            $parts = parse_url($url);
            if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
                throw new FederationException('FederationDrop requiere URLs HTTPS válidas.', 503);
            }
        }
        if (strlen($this->webhookSecret) < 32) {
            throw new FederationException('FederationDrop requiere ARCADECLOUD_DROP_WEBHOOK_SECRET de al menos 32 caracteres.', 503);
        }
        if (!preg_match('/\A[A-Z]{3}\z/', $this->currency)) {
            throw new FederationException('Moneda FederationDrop inválida.', 503);
        }
        if ($this->baseFeeCents + $this->storageGbDayCents + $this->egressGbCents <= 0) {
            throw new FederationException('Configura una tarifa positiva para FederationDrop.', 503);
        }
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
