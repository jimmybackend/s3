<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

use RuntimeException;

final class AwsUnitPriceCatalog
{
    private function __construct(
        private array $rates,
        private string $currency,
        private string $sourceId
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException('No se encontró el catálogo de precios AWS.');
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['rates'] ?? null)) {
            throw new RuntimeException('El catálogo de precios AWS no es válido.');
        }

        $rates = [];
        foreach ($decoded['rates'] as $unit => $rate) {
            if (!is_string($unit) || $unit === '' || !is_numeric($rate)) {
                continue;
            }
            $rates[$unit] = (float)$rate;
        }

        return new self(
            $rates,
            strtoupper((string)($decoded['currency'] ?? 'USD')),
            trim((string)($decoded['source_id'] ?? 'AWS public pricing'))
        );
    }

    public static function default(): self
    {
        return self::fromFile(dirname(__DIR__, 2) . '/config/activity-cost-pricing.json');
    }

    public function estimate(array $units): array
    {
        $amount = 0.0;
        $known = 0;
        $unknown = [];

        foreach ($units as $unit => $quantity) {
            if (!is_string($unit) || !is_numeric($quantity) || (float)$quantity < 0) {
                continue;
            }
            if (!array_key_exists($unit, $this->rates)) {
                $unknown[] = $unit;
                continue;
            }
            $amount += (float)$quantity * $this->rates[$unit];
            $known++;
        }

        $state = $unknown === [] ? 'complete' : ($known > 0 ? 'partial' : 'unpriced');

        return [
            'amount' => $state === 'unpriced' ? null : $amount,
            'currency' => $this->currency,
            'source' => $this->sourceId,
            'state' => $state,
            'unknown_units' => array_values(array_unique($unknown)),
        ];
    }
}
