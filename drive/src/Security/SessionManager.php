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
        return isset($_SESSION['usuario']) && trim((string) $_SESSION['usuario']) !== '';
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
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    }

    public function userName(): string
    {
        return isset($_SESSION['usuario']) ? (string) $_SESSION['usuario'] : '';
    }
}
