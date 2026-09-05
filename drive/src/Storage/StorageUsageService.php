<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use ArcadeCloud\Drive\Security\SessionManager;
use mysqli;
use RuntimeException;

final class StorageUsageService
{
    private const SESSION_KEY = 'drive_storage_usage';

    public function __construct(
        private mysqli $db,
        private SessionManager $session,
        private int $ttlSeconds = 300
    ) {
    }

    public function getUsage(int $userId, bool $forceRefresh = false): array
    {
        if ($userId <= 0) {
            return $this->payload(0);
        }

        $allCached = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($allCached)) {
            $allCached = [];
        }

        $cached = $allCached[$userId] ?? null;
        if (!$forceRefresh && is_array($cached)) {
            $cachedAt = (int)($cached['cached_at'] ?? 0);
            if ($cachedAt > 0 && (time() - $cachedAt) < $this->ttlSeconds) {
                return $cached;
            }
        }

        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(Tamano),0) FROM FileS3 WHERE user_id_ = ? AND Found = 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar el espacio usado: ' . $this->db->error);
        }

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo consultar el espacio usado: ' . $error);
        }

        $stmt->bind_result($bytes);
        $stmt->fetch();
        $stmt->close();

        $payload = $this->payload((int)$bytes);
        $allCached[$userId] = $payload;
        $this->session->set(self::SESSION_KEY, $allCached);

        return $payload;
    }

    public function invalidate(int $userId): void
    {
        $allCached = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($allCached)) {
            return;
        }

        unset($allCached[$userId]);
        $this->session->set(self::SESSION_KEY, $allCached);
    }

    private function payload(int $bytes): array
    {
        return [
            'bytes' => max(0, $bytes),
            'formatted' => $this->formatBytes(max(0, $bytes)),
            'cached_at' => time(),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $value = (float)$bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        if ($index === 0) {
            return (string)$bytes . ' B';
        }

        return number_format($value, 2, '.', '') . ' ' . $units[$index];
    }
}
