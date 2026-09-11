<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use ArcadeCloud\Drive\Core\DriveApplication;
use InvalidArgumentException;

final class SuperAdminReauthenticationService
{
    public const TTL_SECONDS = 600;
    private const EXPIRES_KEY = 'superadmin_reauth_expires_at';
    private const USER_KEY = 'superadmin_reauth_user_id';

    public function __construct(private DriveApplication $app) {}

    public function verify(string $password): void
    {
        if ($password === '') throw new InvalidArgumentException('Confirma tu contraseña actual de superusuario.');
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || !$session->isSuperAdmin()) {
            $this->clear();
            throw new InvalidArgumentException('Se requiere una sesión superadmin válida.');
        }

        $email = trim($session->userName());
        $row = $email !== '' ? (new AuthenticationRepository($this->app->db()))->findUserByEmail($email) : null;
        $hash = is_array($row) ? (string)($row['password'] ?? '') : '';
        $role = is_array($row) ? (string)($row['system_role'] ?? '') : '';
        if ($hash === '' || !hash_equals('superadmin', $role) || !password_verify($password, $hash)) {
            $this->clear();
            throw new InvalidArgumentException('Contraseña de superusuario incorrecta.');
        }

        $session->set(self::USER_KEY, $session->userId());
        $session->set(self::EXPIRES_KEY, time() + self::TTL_SECONDS);
    }

    public function requireRecent(string $password = ''): void
    {
        if ($this->isElevated()) return;
        $this->verify($password);
    }

    public function isElevated(): bool
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || !$session->isSuperAdmin()) {
            $this->clear();
            return false;
        }

        $storedUserId = (int)$session->get(self::USER_KEY, 0);
        $expiresAt = (int)$session->get(self::EXPIRES_KEY, 0);
        if ($storedUserId <= 0 || $storedUserId !== $session->userId() || $expiresAt <= time()) {
            $this->clear();
            return false;
        }
        return true;
    }

    public function remainingSeconds(): int
    {
        if (!$this->isElevated()) return 0;
        $session = $this->app->session();
        return max(0, (int)$session->get(self::EXPIRES_KEY, 0) - time());
    }

    public function state(): array
    {
        $remaining = $this->remainingSeconds();
        return [
            'reauth_required' => $remaining <= 0,
            'reauth_expires_in' => $remaining,
            'reauth_ttl_seconds' => self::TTL_SECONDS,
        ];
    }

    public function clear(): void
    {
        $session = $this->app->session();
        $session->start();
        $session->remove(self::EXPIRES_KEY);
        $session->remove(self::USER_KEY);
    }
}
