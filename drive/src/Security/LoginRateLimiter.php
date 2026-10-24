<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use RuntimeException;

final class LoginRateLimiter
{
    private const WINDOW_SECONDS = 900;
    private const MAX_ACCOUNT_IP_FAILURES = 5;
    private const MAX_IP_FAILURES = 30;

    public function __construct(private ?string $directory = null, private ?\Closure $clock = null)
    {
        $this->directory = $this->directory ?: rtrim(sys_get_temp_dir(), '/') . '/arcadecloud-drive-login-rate';
    }

    public function allow(string $identifier, string $ipAddress): bool
    {
        return !$this->status($identifier, $ipAddress)['blocked'];
    }

    /** Server-authoritative values: never accept counts or deadlines from the browser. */
    public function status(string $identifier, string $ipAddress): array
    {
        $now = $this->now();
        $pair = $this->activeState($this->read($this->pairKey($identifier, $ipAddress)), $now);
        $ip = $this->activeState($this->read($this->ipKey($ipAddress)), $now);
        $until = 0;
        foreach ([[$pair, self::MAX_ACCOUNT_IP_FAILURES], [$ip, self::MAX_IP_FAILURES]] as [$state, $limit]) {
            if ((int)($state['count'] ?? 0) >= $limit) {
                // Existing limiter files retain their original expiration on deployment.
                $until = max($until, (int)($state['blocked_until'] ?? ((int)$state['first_at'] + self::WINDOW_SECONDS)));
            }
        }
        return [
            'blocked' => $until > $now,
            'remaining_attempts' => max(0, min(
                self::MAX_ACCOUNT_IP_FAILURES - (int)($pair['count'] ?? 0),
                self::MAX_IP_FAILURES - (int)($ip['count'] ?? 0)
            )),
            'retry_after' => max(0, $until - $now),
            'blocked_until' => $until,
            'max_attempts' => self::MAX_ACCOUNT_IP_FAILURES,
            'ip_max_attempts' => self::MAX_IP_FAILURES,
            'window_seconds' => self::WINDOW_SECONDS,
        ];
    }

    /** Serialize web authentication for one IP, including verification and failure recording. */
    public function synchronized(string $ipAddress, \Closure $callback): mixed
    {
        $this->ensureDirectory();
        $handle = @fopen($this->directory . '/attempt-' . hash('sha256', $ipAddress) . '.lock', 'c+');
        if (!is_resource($handle)) throw new RuntimeException('Control de acceso no disponible.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Control de acceso no disponible.');
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function registerFailure(string $identifier, string $ipAddress): void
    {
        $this->increment($this->pairKey($identifier, $ipAddress), self::MAX_ACCOUNT_IP_FAILURES);
        $this->increment($this->ipKey($ipAddress), self::MAX_IP_FAILURES);
    }

    public function clear(string $identifier, string $ipAddress): void
    {
        // Keep the inode stable so concurrent readers/writers share the same lock.
        $this->writeState($this->pairKey($identifier, $ipAddress), static fn(array $state): array => []);
    }

    private function pairKey(string $identifier, string $ipAddress): string
    {
        return 'pair-' . hash('sha256', strtolower(trim($identifier)) . "\n" . trim($ipAddress));
    }

    private function ipKey(string $ipAddress): string
    {
        return 'ip-' . hash('sha256', trim($ipAddress));
    }

    private function activeState(array $state, int $now): array
    {
        $expires = (int)($state['blocked_until'] ?? ((int)($state['first_at'] ?? 0) + self::WINDOW_SECONDS));
        return $expires > $now ? $state : [];
    }

    private function increment(string $key, int $limit): void
    {
        $this->writeState($key, function (array $state) use ($limit): array {
            $now = $this->now();
            $state = $this->activeState($state, $now);
            if ((int)($state['count'] ?? 0) >= $limit) return $state; // Retries never extend the block.
            if ($state === []) $state = ['first_at' => $now, 'count' => 0];
            $state['count']++;
            $state['last_at'] = $now;
            if ($state['count'] >= $limit) $state['blocked_until'] = $now + self::WINDOW_SECONDS;
            return $state;
        });
    }

    private function writeState(string $key, \Closure $update): void
    {
        $this->ensureDirectory();
        $path = $this->path($key);
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) throw new RuntimeException('No se pudo guardar el control de acceso.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('No se pudo bloquear el control de acceso.');
            rewind($handle);
            $state = $this->decode(stream_get_contents($handle));
            $encoded = json_encode($update($state), JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || !rewind($handle)
                || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new RuntimeException('No se pudo guardar el control de acceso.');
            }
            @chmod($path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function read(string $key): array
    {
        $path = $this->path($key);
        if (!is_file($path)) return [];
        $handle = @fopen($path, 'r');
        if (!is_resource($handle)) throw new RuntimeException('No se pudo leer el control de acceso.');
        try {
            if (!flock($handle, LOCK_SH)) throw new RuntimeException('No se pudo leer el control de acceso.');
            return $this->decode(stream_get_contents($handle));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function decode(string|false $raw): array
    {
        if ($raw === '') return [];
        if ($raw === false) throw new RuntimeException('Control de acceso ilegible.');
        $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new RuntimeException('Control de acceso inválido.');
        return $state;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear el control de acceso.');
        }
    }

    private function now(): int { return $this->clock === null ? time() : ($this->clock)(); }
    private function path(string $key): string { return rtrim($this->directory, '/') . '/' . $key . '.json'; }
}
