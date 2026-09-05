<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http;

use RuntimeException;

final class Request
{
    public function __construct(
        private array $server,
        private array $query,
        private array $post,
        private array $files = []
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_FILES);
    }

    public function method(): string
    {
        return strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function requireMethod(string $method): void
    {
        if ($this->method() !== strtoupper($method)) {
            throw new RuntimeException('Método no permitido.');
        }
    }

    public function postString(string $name, string $default = ''): string
    {
        $value = $this->post[$name] ?? $default;
        if (is_array($value) || is_object($value)) {
            return $default;
        }
        return trim((string)$value);
    }

    public function postRawString(string $name, string $default = ''): string
    {
        $value = $this->post[$name] ?? $default;
        return is_scalar($value) ? (string)$value : $default;
    }

    public function postInt(string $name, int $default = 0): int
    {
        $value = $this->post[$name] ?? $default;
        return is_scalar($value) ? (int)$value : $default;
    }

    public function postArray(string $name): array
    {
        $value = $this->post[$name] ?? [];
        return is_array($value) ? $value : [];
    }

    public function postJsonArray(string $name): array
    {
        $raw = $this->post[$name] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_scalar($raw)) {
            return [];
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function serverString(string $name, string $default = ''): string
    {
        $value = $this->server[$name] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    public function queryString(string $name, string $default = ''): string
    {
        $value = $this->query[$name] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    public function hasQuery(string $name): bool
    {
        return array_key_exists($name, $this->query);
    }

    public function hasPost(string $name): bool
    {
        return array_key_exists($name, $this->post);
    }

    public function allPost(): array
    {
        return $this->post;
    }

    public function files(): array
    {
        return $this->files;
    }
}
