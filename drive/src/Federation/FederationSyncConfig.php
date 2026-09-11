<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationSyncConfig
{
    public function __construct(
        private int $batchSize,
        private int $peersPerRun,
        private int $scanLimit
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::envInt('ARCADECLOUD_FEDERATION_SYNC_BATCH', 25, 1, 50),
            self::envInt('ARCADECLOUD_FEDERATION_SYNC_PEERS', 3, 1, 10),
            self::envInt('ARCADECLOUD_FEDERATION_SYNC_SCAN', 50, 5, 100)
        );
    }

    public function batchSize(): int { return $this->batchSize; }
    public function peersPerRun(): int { return $this->peersPerRun; }
    public function scanLimit(): int { return $this->scanLimit; }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || trim((string)$raw) === '') return $default;
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $value === false ? $default : (int)$value;
    }
}
