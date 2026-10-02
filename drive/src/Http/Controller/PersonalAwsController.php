<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Aws\PersonalAwsRuntime;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\View\PersonalAwsPageRenderer;
use Throwable;

final class PersonalAwsController
{
    public function __construct(
        private PersonalAwsRuntime $runtime,
        private Request $request,
        private PersonalAwsPageRenderer $renderer = new PersonalAwsPageRenderer()
    ) {
    }

    public function handle(): never
    {
        $jsonGenerate = $this->request->method() === 'POST' && $this->request->postString('action') === 'generate' && $this->request->postString('response_format') === 'json';
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');

        $access = $this->runtime->access();
        $state = $access->state();

        if ($state === 'forbidden') {
            if ($jsonGenerate || $this->isEmbeddedRequest()) {
                JsonResponse::send(['ok' => false, 'result' => null, 'error' => 'Esta herramienta personal no está disponible para esta cuenta.'], 403);
            }
            $this->renderer->forbidden();
        }

        if ($state === 'locked') {
            if ($jsonGenerate || ($this->isEmbeddedRequest() && $this->request->postString('action') === 'generate')) {
                JsonResponse::send(['ok' => false, 'result' => null, 'error' => 'La sesión de la herramienta TOTP está bloqueada.'], 401);
            }
            if ($this->request->method() === 'POST' && $this->request->postString('action') === 'unlock') {
                if ($access->unlock($this->request->postRawString('access_password'))) {
                    header('Location: aws.php');
                    exit;
                }

                usleep(350000);
                $this->renderer->locked(
                    $this->runtime->config()->passwordHash() !== '',
                    'Acceso no autorizado.'
                );
            }

            $this->renderer->locked(
                $this->runtime->config()->passwordHash() !== ''
            );
        }

        $result = null;
        $error = '';

        if ($this->request->method() === 'POST' && $this->request->postString('action') === 'generate') {
            try {
                $result = $this->runtime->totp()->generate(
                    $this->request->postString('account_id')
                );
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            if ($jsonGenerate || $this->isEmbeddedRequest()) {
                JsonResponse::send([
                    'ok' => $error === '',
                    'result' => $result,
                    'error' => $error,
                ], $error === '' ? 200 : 422);
            }
        }

        $this->renderer->tool(
            $this->runtime->totp()->accounts(),
            $result,
            $error
        );
    }

    private function isEmbeddedRequest(): bool
    {
        if ($this->request->serverString('HTTP_X_ARCADECLOUD_EMBED') === '1') {
            return true;
        }

        if (
            $this->request->method() === 'POST'
            && $this->request->postString('action') === 'generate'
            && $this->request->postString('arcadecloud_os') === '1'
        ) {
            return true;
        }

        // Some reverse proxies do not forward custom request headers. The OS
        // also sends this same-origin query marker so the endpoint can retain
        // its JSON contract rather than rendering a complete HTML document.
        return $this->request->queryString('arcadecloud_os') === '1';
    }
}
