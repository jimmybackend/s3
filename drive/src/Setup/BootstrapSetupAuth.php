<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Setup;

use RuntimeException;

final class BootstrapSetupAuth
{
    public const DEFAULT_AUTH_PATH = '/etc/arcadecloud-drive/bootstrap-auth.json';
    public const DEFAULT_LOCK_PATH = '/etc/arcadecloud-drive/setup.lock';

    private const TOKEN_OK_KEY = 'arcadecloud_setup_token_ok';
    private const AUTH_OK_KEY = 'arcadecloud_setup_authenticated';
    private const CSRF_KEY = 'arcadecloud_setup_csrf';
    private const ATTEMPTS_KEY = 'arcadecloud_setup_attempts';
    private const LOCKED_UNTIL_KEY = 'arcadecloud_setup_locked_until';

    public function __construct(
        private string $authPath = self::DEFAULT_AUTH_PATH,
        private string $lockPath = self::DEFAULT_LOCK_PATH
    ) {
        $this->ensureSession();
    }

    public function isLocked(): bool
    {
        return is_file($this->lockPath);
    }

    public function isAvailable(): bool
    {
        return !$this->isLocked() && is_file($this->authPath) && is_readable($this->authPath);
    }

    public function status(): array
    {
        if ($this->isLocked()) {
            $this->clearSessionState();
            return [
                'available' => false,
                'locked' => true,
                'token_validated' => false,
                'authenticated' => false,
            ];
        }

        return [
            'available' => $this->isAvailable(),
            'locked' => false,
            'token_validated' => (bool)($_SESSION[self::TOKEN_OK_KEY] ?? false),
            'authenticated' => (bool)($_SESSION[self::AUTH_OK_KEY] ?? false),
        ];
    }

    public function acceptActivationToken(string $token): bool
    {
        if (!$this->isAvailable()) return false;
        $token = strtolower(trim($token));
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) return false;

        $auth = $this->readAuth();
        $expected = strtolower((string)($auth['token_hash'] ?? ''));
        $candidate = hash('sha256', $token);
        if ($expected === '' || !hash_equals($expected, $candidate)) return false;

        $_SESSION[self::TOKEN_OK_KEY] = true;
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        return true;
    }

    public function login(string $username, string $password, string $csrf): bool
    {
        if (!$this->isAvailable() || !(bool)($_SESSION[self::TOKEN_OK_KEY] ?? false)) return false;
        $this->assertCsrf($csrf);

        $lockedUntil = (int)($_SESSION[self::LOCKED_UNTIL_KEY] ?? 0);
        if ($lockedUntil > time()) {
            throw new RuntimeException('Demasiados intentos. Intenta nuevamente en unos segundos.');
        }

        $auth = $this->readAuth();
        $expectedUser = (string)($auth['username'] ?? '');
        $hash = (string)($auth['password_hash'] ?? '');
        $valid = $expectedUser !== ''
            && hash_equals($expectedUser, trim($username))
            && $hash !== ''
            && password_verify($password, $hash);

        if (!$valid) {
            $attempts = (int)($_SESSION[self::ATTEMPTS_KEY] ?? 0) + 1;
            $_SESSION[self::ATTEMPTS_KEY] = $attempts;
            if ($attempts >= 5) {
                $_SESSION[self::LOCKED_UNTIL_KEY] = time() + 30;
                $_SESSION[self::ATTEMPTS_KEY] = 0;
            }
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::AUTH_OK_KEY] = true;
        $_SESSION[self::ATTEMPTS_KEY] = 0;
        $_SESSION[self::LOCKED_UNTIL_KEY] = 0;
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        return true;
    }

    public function isAuthenticated(): bool
    {
        return $this->isAvailable()
            && (bool)($_SESSION[self::TOKEN_OK_KEY] ?? false)
            && (bool)($_SESSION[self::AUTH_OK_KEY] ?? false);
    }

    public function requireAuthenticated(string $csrf): void
    {
        if (!$this->isAuthenticated()) throw new RuntimeException('Supervisor de instalación no autenticado.');
        $this->assertCsrf($csrf);
    }

    public function csrfToken(): string
    {
        if (!(bool)($_SESSION[self::TOKEN_OK_KEY] ?? false)) return '';
        $csrf = (string)($_SESSION[self::CSRF_KEY] ?? '');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $csrf)) {
            $csrf = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_KEY] = $csrf;
        }
        return $csrf;
    }

    public function logout(): void
    {
        $this->clearSessionState();
        session_regenerate_id(true);
    }

    private function readAuth(): array
    {
        if (!$this->isAvailable()) throw new RuntimeException('El supervisor temporal de instalación no está disponible.');
        $decoded = json_decode((string)file_get_contents($this->authPath), true);
        if (!is_array($decoded) || ($decoded['enabled'] ?? null) !== true) {
            throw new RuntimeException('La credencial bootstrap tiene formato inválido o está deshabilitada.');
        }
        return $decoded;
    }

    private function assertCsrf(string $csrf): void
    {
        $expected = $this->csrfToken();
        if ($expected === '' || $csrf === '' || !hash_equals($expected, $csrf)) {
            throw new RuntimeException('Token CSRF de setup inválido. Recarga el asistente.');
        }
    }

    private function clearSessionState(): void
    {
        unset(
            $_SESSION[self::TOKEN_OK_KEY],
            $_SESSION[self::AUTH_OK_KEY],
            $_SESSION[self::CSRF_KEY],
            $_SESSION[self::ATTEMPTS_KEY],
            $_SESSION[self::LOCKED_UNTIL_KEY]
        );
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}
