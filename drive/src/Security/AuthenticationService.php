<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

final class AuthenticationService
{
    public function __construct(
        private AuthenticationRepository $repository,
        private SessionManager $session
    ) {
    }

    public function authenticate(string $email, string $password, string $ipAddress): array
    {
        $user = $this->repository->findUserByEmail($email);
        if ($user === null) {
            return ['status' => 'invalid_credentials'];
        }

        $hash = (string)($user['password'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return ['status' => 'invalid_credentials'];
        }

        if ((string)($user['userstatus'] ?? '') !== 'Activo') {
            return ['status' => 'inactive'];
        }

        $userId = (int)($user['id'] ?? 0);
        $role = (string)($user['role'] ?? '');
        $systemRole = (string)($user['system_role'] ?? 'user');

        $this->session->establishAuthenticatedUser($email, $userId, $role, $systemRole);
        $this->repository->recordLogin($userId, $role, $ipAddress);

        return [
            'status' => 'authenticated',
            'user_id' => $userId,
            'role' => $role,
            'system_role' => $systemRole,
        ];
    }
}
