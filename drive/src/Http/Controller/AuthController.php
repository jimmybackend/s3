<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\Security\LoginRateLimiter;
use ArcadeCloud\Drive\Security\LoginPageGuard;
use Throwable;

final class AuthController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function login(): never
    {
        $session = $this->app->session();
        $session->start();

        if ($this->request->method() !== 'POST') {
            $this->redirect('index.php');
        }

        header('Cache-Control: no-store');
        LoginPageGuard::enforce();
        $email = trim($this->request->postRawString('email'));
        $password = $this->request->postRawString('password');
        $ipAddress = $this->request->serverString('REMOTE_ADDR');
        $limiter = new LoginRateLimiter();
        // Store only the identifier for server-side page guards. Never store the password.
        $email = strlen($email) <= 320 ? $email : '';
        $session->set('login_attempt_identifier', $email);

        try {
            $result = $limiter->synchronized($ipAddress, function () use ($limiter, $email, $password, $ipAddress): array {
                if (!$limiter->allow($email, $ipAddress)) return ['status' => 'blocked'];
                $result = $email === '' || $password === '' || $this->containsRejectedCharacters($email)
                    ? ['status' => 'invalid_credentials']
                    : $this->app->authenticationService()->authenticate($email, $password, $ipAddress);
                if (($result['status'] ?? '') !== 'authenticated') {
                    $limiter->registerFailure($email, $ipAddress);
                } else {
                    $limiter->clear($email, $ipAddress);
                }
                return $result;
            });
        } catch (Throwable $e) {
            // Fail closed when the limiter cannot persist a failed attempt.
            // AuthenticationService may have established a session before a later write failed.
            $session->destroy();
            LoginPageGuard::unavailable($e);
        }

        if (($result['status'] ?? '') !== 'authenticated') {
            $session->set('login_credentials_error', true);
            $this->redirect('login-error.php');
        }
        $session->remove('login_attempt_identifier');
        $session->remove('login_credentials_error');

        if (($result['status'] ?? '') === 'authenticated') {
            // Conservamos explícita la rama de superadmin para que las
            // garantías/auditorías de rol sigan siendo visibles, aunque ArcadeCloud
            // OS sea ahora la experiencia principal para todos los usuarios.
            $systemRole = (string)($result['system_role'] ?? 'user');
            if ($systemRole === 'superadmin') {
                $this->redirect('so.php');
            }

            // El Drive clásico continúa disponible desde el propio SO.
            $this->redirect('so.php');
        }

        exit;
    }

    public function logout(): never
    {
        $this->app->session()->destroy();
        $this->redirect('index.php');
    }

    private function containsRejectedCharacters(string $value): bool
    {
        return preg_match("/[',\\s%()]/", $value) === 1;
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location, true, 303);
        exit;
    }
}

