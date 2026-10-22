<?php
declare(strict_types=1);

// Isolated storage double; the real shared AuthenticationService and verifier run below.
// No production credentials, database, or AWS calls.
namespace ArcadeCloud\Drive\Security {
    final class AuthenticationRepository
    {
        public int $logins = 0;
        public function __construct(public array $user) {}
        public function findUserByEmail(string $email): ?array
        {
            return $email === 'native@example.test' ? $this->user : null;
        }
        public function updatePasswordHash(int $userId, string $hash): void
        {
            $this->user['password'] = $hash;
        }
        public function recordLogin(int $userId, string $role, string $ip): void { $this->logins++; }
    }
}
namespace {
    require_once __DIR__ . '/../src/Security/AuthenticationService.php';
    require_once __DIR__ . '/../src/Security/PasswordCredentialVerifier.php';
    require_once __DIR__ . '/../src/Security/SessionManager.php';
    require_once __DIR__ . '/../src/Http/Request.php';

    $checks = 0;
    $assert = static function(bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new \RuntimeException($label);
        $checks++;
    };
    $algorithms = [PASSWORD_BCRYPT];
    if (defined('PASSWORD_ARGON2ID')) $algorithms[] = PASSWORD_ARGON2ID;
    foreach ($algorithms as $algorithm) {
        foreach (['123456', ' aB+&%ñ= 123 ', 'AbC!"\'()$\\end'] as $password) {
            $hash = password_hash($password, $algorithm);
            $repo = new \ArcadeCloud\Drive\Security\AuthenticationRepository([
                'id' => 2, 'password' => $hash, 'userstatus' => 'Activo',
                'role' => 'user', 'system_role' => 'user',
            ]);
            $session = new \ArcadeCloud\Drive\Security\SessionManager();
            $session->start(); $_SESSION = [];
            $service = new \ArcadeCloud\Drive\Security\AuthenticationService($repo, $session);
            // application/x-www-form-urlencoded, as used by Android's FormBody.
            parse_str(http_build_query(['email' => 'native@example.test', 'password' => $password], '', '&', PHP_QUERY_RFC1738), $post);
            $request = new \ArcadeCloud\Drive\Http\Request([], [], $post);
            $candidate = $request->postRawString('password');
            $assert($candidate === $password, 'Form decoding changed the credential');
            $result = $service->authenticate($request->postString('email'), $candidate, '127.0.0.1');
            $assert($result['status'] === 'authenticated' && $session->userId() === 2, 'Real hash authentication rejected');
            $assert($repo->user['password'] === $hash && $repo->logins === 1, 'Hashed credentials must not be rewritten');
            $assert($service->authenticate('native@example.test', $hash, '127.0.0.1')['status'] === 'invalid_credentials', 'Hash itself must not authenticate');
            $assert($service->authenticate('native@example.test', $password . 'x', '127.0.0.1')['status'] === 'invalid_credentials', 'Incorrect password accepted');
            if (trim($password) !== $password) {
                $assert($service->authenticate('native@example.test', trim($password), '127.0.0.1')['status'] === 'invalid_credentials', 'Significant spaces lost');
            }
            $repo->user['userstatus'] = 'Inactivo';
            $assert($service->authenticate('native@example.test', $password, '127.0.0.1')['status'] === 'inactive', 'Inactive account accepted');
            $_SESSION = [];
        }
    }
    echo "native_authentication_regression: $checks assertions passed (real shared hash verifier, isolated storage)\n";
}
