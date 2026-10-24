<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

require_once __DIR__ . '/SessionManager.php';
require_once __DIR__ . '/LoginRateLimiter.php';

/** Lightweight public-page guard; no database or AWS bootstrap required. */
final class LoginPageGuard
{
    public static function context(): array
    {
        (new SessionManager())->start();
        header('Cache-Control: no-store');
        $identifier = (string)($_SESSION['login_attempt_identifier'] ?? '');
        return (new LoginRateLimiter())->status($identifier, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    public static function enforce(): void
    {
        try {
            if (self::context()['blocked']) {
                header('Location: login-error.php', true, 303);
                exit;
            }
        } catch (\Throwable $error) {
            self::unavailable($error);
        }
    }

    public static function unavailable(\Throwable $error): never
    {
        error_log('Drive login access control: ' . $error->getMessage());
        http_response_code(503);
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'El acceso no está disponible temporalmente. Inténtalo más tarde.';
        exit;
    }
}
