<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\Security\LoginRateLimiter;
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

        if (!$this->request->hasPost('email') || !$this->request->hasPost('password')) {
            $this->redirect('index.php');
        }

        $email = $this->request->postRawString('email');
        $password = $this->request->postRawString('password');

        $ipAddress = $this->request->serverString('REMOTE_ADDR');
        $limiter = new LoginRateLimiter();

        try {
            if (!$limiter->allow($email, $ipAddress)) {
                $this->redirect('https://esforzados.com/index.php?x=101');
            }
        } catch (Throwable $e) {
            error_log('Drive login limiter error: ' . $e->getMessage());
            http_response_code(503);
            echo 'El inicio de sesión no está disponible temporalmente.';
            exit;
        }

        if ($this->containsRejectedCharacters($email)) {
            $this->redirect('https://esforzados.com/index.php?x=100');
        }

        try {
            $result = $this->app->authenticationService()->authenticate(
                $email,
                $password,
                $ipAddress
            );
        } catch (Throwable $e) {
            error_log('Drive authentication error: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'No se pudo completar el inicio de sesión.';
            exit;
        }

        if (($result['status'] ?? '') === 'invalid_credentials') {
            try {
                $limiter->registerFailure($email, $ipAddress);
            } catch (Throwable $e) {
                error_log('Drive login limiter failure-record error: ' . $e->getMessage());
            }
            $this->redirect('303.html');
        }

        if (($result['status'] ?? '') === 'inactive') {
            $this->redirect('202.html');
        }

        if (($result['status'] ?? '') === 'authenticated') {
            try {
                $limiter->clear($email, $ipAddress);
            } catch (Throwable $e) {
                error_log('Drive login limiter clear error: ' . $e->getMessage());
            }

            $role = (string)($result['role'] ?? '');
            $systemRole = (string)($result['system_role'] ?? 'user');
            if ($systemRole === 'superadmin' || $role === 'Administración' || $role === 'Soporte') {
                $this->redirect('s3.php');
            }
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
        header('Location: ' . $location);
        exit;
    }
}
