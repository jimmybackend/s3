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

        $storedPassword = (string)($user['password'] ?? '');
        $verification = PasswordCredentialVerifier::verify($password, $storedPassword);
        if (!($verification['valid'] ?? false)) {
            return ['status' => 'invalid_credentials'];
        }

        $userId = (int)($user['id'] ?? 0);
        if (($verification['migrate_plaintext'] ?? false) === true) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                return ['status' => 'invalid_credentials'];
            }
            $this->repository->updatePasswordHash($userId, $passwordHash);
        }

        if ((string)($user['userstatus'] ?? '') !== 'Activo') {
            return ['status' => 'inactive'];
        }

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
