<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use RuntimeException;

final class LoginRateLimiter
{
    private const WINDOW_SECONDS = 900;
    private const MAX_ACCOUNT_IP_FAILURES = 5;
    private const MAX_IP_FAILURES = 30;

    public function __construct(private ?string $directory = null)
    {
        $this->directory = $this->directory ?: rtrim(sys_get_temp_dir(), '/') . '/arcadecloud-drive-login-rate';
    }

    public function allow(string $identifier, string $ipAddress): bool
    {
        return $this->count($this->pairKey($identifier, $ipAddress)) < self::MAX_ACCOUNT_IP_FAILURES
            && $this->count($this->ipKey($ipAddress)) < self::MAX_IP_FAILURES;
    }

    public function registerFailure(string $identifier, string $ipAddress): void
    {
        $this->increment($this->pairKey($identifier, $ipAddress));
        $this->increment($this->ipKey($ipAddress));
    }

    public function clear(string $identifier, string $ipAddress): void
    {
        $this->delete($this->pairKey($identifier, $ipAddress));
    }

    private function pairKey(string $identifier, string $ipAddress): string
    {
        return 'pair-' . hash('sha256', strtolower(trim($identifier)) . "\n" . trim($ipAddress));
    }

    private function ipKey(string $ipAddress): string
    {
        return 'ip-' . hash('sha256', trim($ipAddress));
    }

    private function count(string $key): int
    {
        $state = $this->read($key);
        if ($state === null) {
            return 0;
        }

        if ((int)($state['first_at'] ?? 0) < time() - self::WINDOW_SECONDS) {
            $this->delete($key);
            return 0;
        }

        return max(0, (int)($state['count'] ?? 0));
    }

    private function increment(string $key): void
    {
        $this->ensureDirectory();
        $path = $this->path($key);
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('No se pudo actualizar el control temporal de acceso.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear el control temporal de acceso.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $now = time();

            if (!is_array($state) || (int)($state['first_at'] ?? 0) < $now - self::WINDOW_SECONDS) {
                $state = ['first_at' => $now, 'count' => 0];
            }

            $state['count'] = min(100000, max(0, (int)$state['count']) + 1);
            $state['last_at'] = $now;

            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, $encoded) === false) {
                throw new RuntimeException('No se pudo guardar el control temporal de acceso.');
            }
            fflush($handle);
            @chmod($path, 0600);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function read(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear el control temporal de acceso.');
        }

        @chmod($this->directory, 0700);
    }

    private function path(string $key): string
    {
        return rtrim((string)$this->directory, '/') . '/' . $key . '.json';
    }
}
