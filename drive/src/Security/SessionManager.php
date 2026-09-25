<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

final class SessionManager
{
    public function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['usuario']) && trim((string)$_SESSION['usuario']) !== '';
    }

    public function requireAuthenticated(string $redirect = 'index.php'): void
    {
        if ($this->isAuthenticated()) {
            return;
        }
        header('Location: ' . $redirect);
        exit;
    }

    public function userId(): int
    {
        foreach (['user_id', 'user_id_', 'id_usuario', 'id_user', 'id'] as $key) {
            $value = $_SESSION[$key] ?? null;
            if ($value !== null && $value !== '' && ctype_digit((string)$value)) {
                return (int)$value;
            }
        }
        return 0;
    }

    public function userName(): string
    {
        return isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : '';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clearSecureAccessKeys(array $keys): void
    {
        if (!isset($_SESSION['secure_ok_files']) || !is_array($_SESSION['secure_ok_files'])) {
            return;
        }
        foreach (array_unique(array_filter(array_map('strval', $keys))) as $key) {
            unset($_SESSION['secure_ok_files'][$key]);
        }
    }
}
