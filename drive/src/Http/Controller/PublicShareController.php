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

        $this->handleGet($endpointKind);
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
                'mensaje' => $e->getMessage(),
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

            if ($this->request->queryString('direct') !== '' || $this->request->queryString('download') !== '') {
                header('Location: ' . (string)$share['url']);
                exit;
            }

            $kind = $this->renderer->resolveKind(
                $endpointKind,
                (string)($share['tipo'] ?? 'otro'),
                (string)$share['key']
            );

            if ($this->request->queryString('json') !== '') {
                if ($kind === 'text') {
                    $content = $token !== ''
                        ? $this->app->shareAccessService()->readPublicContent($share)
                        : $this->app->shareAccessService()->privateContent(
                            $this->authenticatedUserId(),
                            (string)$share['key']
                        )['contenido'];

                    JsonResponse::send([
                        'estado' => 'ok',
                        'archivo' => $share['key'],
                        'contenido' => $content,
                    ]);
                }

                JsonResponse::send([
                    'estado' => 'ok',
                    'archivo' => $share['key'],
                    'url' => $share['url'],
                ]);
            }

            $content = null;
            if ($kind === 'text') {
                $content = $token !== ''
                    ? $this->app->shareAccessService()->readPublicContent($share)
                    : $this->app->shareAccessService()->privateContent(
                        $this->authenticatedUserId(),
                        (string)$share['key']
                    )['contenido'];
            }

            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderer->render($kind, $share, $content);
        } catch (ShareException $e) {
            http_response_code($e->httpStatus());
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderer->error($e->getMessage());
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderer->error('No se pudo abrir el enlace compartido.');
        }
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
}
