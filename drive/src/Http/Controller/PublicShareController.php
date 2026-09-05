<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\Sharing\ShareException;
use ArcadeCloud\Drive\View\SharePageRenderer;
use Throwable;

final class PublicShareController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request,
        private ?SharePageRenderer $renderer = null
    ) {
        $this->renderer ??= new SharePageRenderer();
    }

    public function handle(string $endpointKind): void
    {
        if ($this->request->method() === 'POST') {
            $this->handlePrivatePost($endpointKind);
            return;
        }

        if ($this->request->method() !== 'GET') {
            $this->renderError(new ShareException('Método no permitido.', 405));
            return;
        }

        $this->handleGet($endpointKind);
    }

    public function legacy(): void
    {
        if ($this->request->method() !== 'GET') {
            $this->renderError(new ShareException('Método no permitido.', 405));
            return;
        }

        try {
            $token = $this->request->queryString(
                't',
                $this->request->queryString('token')
            );

            if ($token === '') {
                throw new ShareException('Falta parámetro de token.', 400);
            }

            $share = $this->app->shareAccessService()->publicToken($token);

            if ($this->request->hasQuery('direct') || $this->request->hasQuery('download')) {
                header('Location: ' . (string)$share['url']);
                exit;
            }

            $kind = $this->renderer->resolveKind(
                '',
                (string)($share['tipo'] ?? 'otro'),
                (string)$share['key']
            );

            $content = $kind === 'text'
                ? $this->app->shareAccessService()->readPublicContent($share)
                : null;

            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderer->render($kind, $share, $content);
        } catch (ShareException $e) {
            $this->renderError($e);
        } catch (Throwable $e) {
            $this->renderError(new ShareException('No se pudo abrir el enlace compartido.', 500));
        }
    }

    private function handlePrivatePost(string $endpointKind): void
    {
        try {
            $userId = $this->authenticatedUserId();
            $key = $this->request->postString('archivo');
            if ($key === '') {
                throw new ShareException('Archivo no especificado.', 400);
            }

            if ($endpointKind === 'texto') {
                $result = $this->app->shareAccessService()->privateContent($userId, $key);
                JsonResponse::send([
                    'estado' => 'ok',
                    'contenido' => $result['contenido'],
                ]);
            }

            $result = $this->app->shareAccessService()->privateUrl($userId, $key);
            JsonResponse::send([
                'estado' => 'ok',
                'url' => $result['url'],
            ]);
        } catch (ShareException $e) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => $e->getMessage(),
            ], $e->httpStatus());
        } catch (Throwable $e) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => 'No se pudo procesar el archivo.',
            ], 500);
        }
    }

    private function handleGet(string $endpointKind): void
    {
        try {
            $token = $this->request->queryString('t');

            if ($token !== '') {
                $share = $this->app->shareAccessService()->publicToken($token);
            } else {
                $key = $this->request->queryString(
                    'archivo',
                    $this->request->queryString('key')
                );
                if ($key === '') {
                    throw new ShareException('Archivo no especificado.', 400);
                }

                $share = $this->app->shareAccessService()->privateUrl(
                    $this->authenticatedUserId(),
                    $key
                );
                $share['tipo'] = 'otro';
            }

            if ($this->request->hasQuery('direct') || $this->request->hasQuery('download')) {
                header('Location: ' . (string)$share['url']);
                exit;
            }

            $kind = $this->renderer->resolveKind(
                $endpointKind,
                (string)($share['tipo'] ?? 'otro'),
                (string)$share['key']
            );

            if ($this->request->hasQuery('json')) {
                if ($kind === 'text') {
                    JsonResponse::send([
                        'estado' => 'ok',
                        'archivo' => $share['key'],
                        'contenido' => $this->contentFor($share, $token !== ''),
                    ]);
                }

                JsonResponse::send([
                    'estado' => 'ok',
                    'archivo' => $share['key'],
                    'url' => $share['url'],
                ]);
            }

            $content = $kind === 'text'
                ? $this->contentFor($share, $token !== '')
                : null;

            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderer->render($kind, $share, $content);
        } catch (ShareException $e) {
            $this->renderError($e);
        } catch (Throwable $e) {
            $this->renderError(new ShareException('No se pudo abrir el enlace compartido.', 500));
        }
    }

    private function contentFor(array $share, bool $public): string
    {
        if ($public) {
            return $this->app->shareAccessService()->readPublicContent($share);
        }

        return (string)$this->app->shareAccessService()->privateContent(
            $this->authenticatedUserId(),
            (string)$share['key']
        )['contenido'];
    }

    private function authenticatedUserId(): int
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            throw new ShareException('Sesión inválida.', 401);
        }

        return $session->userId();
    }

    private function renderError(ShareException $e): void
    {
        http_response_code($e->httpStatus());
        header('Content-Type: text/html; charset=UTF-8');
        echo $this->renderer->error($e->getMessage());
    }
}
