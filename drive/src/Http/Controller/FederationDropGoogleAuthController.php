<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationDropGoogleAuthService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class FederationDropGoogleAuthController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function login(): never
    {
        try {
            $result = (new FederationDropGoogleAuthService($this->app))->beginLogin(
                $this->request->queryString('source'),
                $this->request->queryString('resource_id')
            );
            $this->redirect(
                (string)$result['url'],
                [(string)$result['set_cookie']],
                302
            );
        } catch (FederationException $e) {
            $this->error($e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            error_log('[FederationDrop Google login] ' . $e->getMessage());
            $this->error('No se pudo iniciar sesión con Google.', 500);
        }
    }

    public function callback(): never
    {
        try {
            $service = new FederationDropGoogleAuthService($this->app);
            $result = $service->completeLogin(
                $this->request->queryString('error'),
                $this->request->queryString('state'),
                $this->request->queryString('code'),
                $this->request->cookieString(FederationDropGoogleAuthService::STATE_COOKIE)
            );
            $cookies = [];
            foreach ((array)($result['set_cookies'] ?? []) as $cookie) {
                if (is_string($cookie) && $cookie !== '') $cookies[] = $cookie;
            }
            $this->redirect((string)$result['redirect_url'], $cookies, 303);
        } catch (FederationException $e) {
            $this->error($e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            error_log('[FederationDrop Google callback] ' . $e->getMessage());
            $this->error('No se pudo completar el inicio de sesión con Google.', 500);
        }
    }

    public function logout(): never
    {
        if ($this->request->method() !== 'POST') {
            $this->error('Método no permitido.', 405);
        }

        try {
            $service = new FederationDropGoogleAuthService($this->app);
            $this->redirect(
                rtrim((string)getenv('ARCADECLOUD_DROP_PUBLIC_URL'), '/') ?: '../federationdrop',
                [$service->clearSessionCookie()],
                303
            );
        } catch (Throwable $e) {
            error_log('[FederationDrop Google logout] ' . $e->getMessage());
            $this->error('No se pudo cerrar la sesión Google.', 500);
        }
    }

    private function redirect(string $url, array $cookies, int $status): never
    {
        if (!$this->safeHttpsUrl($url) && !str_starts_with($url, '../')) {
            $this->error('Redirección FederationDrop inválida.', 500);
        }
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        foreach ($cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        header('Location: ' . $url, true, $status);
        exit;
    }

    private function error(string $message, int $status): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        echo $message;
        exit;
    }

    private function safeHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }
}
