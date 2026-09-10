<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

use mysqli;

final class ActivityCostRecorder
{
    private const BLOCKED_METADATA_PARTS = [
        'password', 'secret', 'credential', 'authorization', 'cookie', 'session',
        'token', 'presigned', 'url', 'access_key', 'private_key', 'totp', 'uploadid', 'key_s3'
    ];

    public function __construct(
        private ActivityCostRepository $repository,
        private AwsUnitPriceCatalog $prices
    ) {
    }

    public static function fromDatabase(mysqli $db): self
    {
        return new self(new ActivityCostRepository($db), AwsUnitPriceCatalog::default());
    }

    public static function correlation(string $kind, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $kind = preg_replace('/[^a-z0-9._-]+/i', '-', $kind) ?: 'event';
        return substr($kind, 0, 24) . ':' . hash('sha256', $value);
    }

    public function success(
        int $userId,
        string $action,
        string $service,
        ?int $fileId = null,
        array $units = [],
        ?float $startedAt = null,
        array $metadata = [],
        ?string $correlationId = null,
        ?int $actorUserId = null
    ): void {
        $this->record('ok', $userId, $action, $service, $fileId, $units, $startedAt, $metadata, $correlationId, $actorUserId);
    }

    public function failure(
        int $userId,
        string $action,
        string $service,
        ?float $startedAt = null,
        array $metadata = [],
        ?string $correlationId = null,
        ?int $actorUserId = null
    ): void {
        $this->record('error', $userId, $action, $service, null, [], $startedAt, $metadata, $correlationId, $actorUserId);
    }

    private function record(
        string $status,
        int $userId,
        string $action,
        string $service,
        ?int $fileId,
        array $units,
        ?float $startedAt,
        array $metadata,
        ?string $correlationId,
        ?int $actorUserId
    ): void {
        if ($userId <= 0) return;

        try {
            $units = $this->normalizeUnits($units);
            $pricing = $this->prices->estimate($units);
            if ($pricing['unknown_units'] !== []) {
                $metadata['unpriced_units'] = $pricing['unknown_units'];
            }

            $this->repository->record([
                'user_id' => $userId,
                'actor_user_id' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : $userId,
                'action' => $this->label($action),
                'service' => $this->label($service),
                'file_id' => $fileId !== null && $fileId > 0 ? $fileId : null,
                'units_json' => $units === [] ? null : json_encode($units, JSON_UNESCAPED_SLASHES),
                'estimated_cost' => $pricing['amount'],
                'currency' => $pricing['currency'],
                'price_source' => $pricing['source'],
                'pricing_state' => $pricing['state'],
                'status' => $status,
                'duration_ms' => $startedAt !== null ? max(0, (int)round((microtime(true) - $startedAt) * 1000)) : null,
                'correlation_id' => $correlationId !== null ? substr($correlationId, 0, 96) : null,
                'metadata_json' => $this->safeMetadataJson($metadata),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Best effort: una falla de telemetría nunca debe romper la operación principal.
        }
    }

    private function normalizeUnits(array $units): array
    {
        $clean = [];
        foreach ($units as $name => $quantity) {
            $name = strtolower(trim((string)$name));
            if ($name === '' || !preg_match('/^[a-z0-9._-]{1,80}$/', $name) || !is_numeric($quantity)) continue;
            $quantity = (float)$quantity;
            if ($quantity < 0) continue;
            $clean[$name] = $quantity;
        }
        ksort($clean);
        return $clean;
    }

    private function label(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';
        return substr($value !== '' ? $value : 'unknown', 0, 64);
    }

    private function safeMetadataJson(array $metadata): ?string
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            $key = strtolower(trim((string)$key));
            if ($key === '' || $this->blockedKey($key)) continue;
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = substr($value, 0, 160);
            } elseif (is_array($value)) {
                $items = [];
                foreach (array_slice($value, 0, 20) as $item) {
                    if (is_scalar($item) || $item === null) $items[] = $item;
                }
                $safe[$key] = $items;
            }
        }
        if ($safe === []) return null;
        $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    private function blockedKey(string $key): bool
    {
        foreach (self::BLOCKED_METADATA_PARTS as $part) {
            if (str_contains($key, $part)) return true;
        }
        return false;
    }
}
