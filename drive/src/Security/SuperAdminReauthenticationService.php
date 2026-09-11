<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use ArcadeCloud\Drive\Core\DriveApplication;
use InvalidArgumentException;

final class SuperAdminReauthenticationService
{
    public function __construct(private DriveApplication $app) {}

    public function verify(string $password): void
    {
        if ($password === '') throw new InvalidArgumentException('Confirma tu contraseña actual de superusuario.');
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || !$session->isSuperAdmin()) {
            throw new InvalidArgumentException('Se requiere una sesión superadmin válida.');
        }

        $email = trim($session->userName());
        $row = $email !== '' ? (new AuthenticationRepository($this->app->db()))->findUserByEmail($email) : null;
        $hash = is_array($row) ? (string)($row['password'] ?? '') : '';
        $role = is_array($row) ? (string)($row['system_role'] ?? '') : '';
        if ($hash === '' || !hash_equals('superadmin', $role) || !password_verify($password, $hash)) {
            throw new InvalidArgumentException('Contraseña de superusuario incorrecta.');
        }
    }
}
