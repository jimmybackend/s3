<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class AuthController
{
    private const MAX_ATTEMPTS = 3;

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

        if ($session->incrementLoginAttempt($email) > self::MAX_ATTEMPTS) {
            $session->destroy();
            $this->redirect('https://esforzados.com/index.php?x=101');
        }

        if ($this->containsRejectedCharacters($email) || $this->containsRejectedCharacters($password)) {
            $this->redirect('https://esforzados.com/index.php?x=100');
        }

        try {
            $result = $this->app->authenticationService()->authenticate(
                $email,
                $password,
                $this->request->serverString('REMOTE_ADDR')
            );
        } catch (Throwable $e) {
            error_log('Drive authentication error: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'No se pudo completar el inicio de sesión.';
            exit;
        }

        if (($result['status'] ?? '') === 'invalid_credentials') {
            $this->redirect('303.html');
        }

        if (($result['status'] ?? '') === 'inactive') {
            $this->redirect('202.html');
        }

        if (($result['status'] ?? '') === 'authenticated') {
            $role = (string)($result['role'] ?? '');
            if ($role === 'Administración' || $role === 'Soporte') {
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
