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
        $this->securityHeaders();
        if ($this->request->method() === 'POST' && $endpointKind === 'texto' && $this->request->postString('action') === 'book_ai_layout') {
            $this->handleBookLayout();
            return;
        }
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
        $this->securityHeaders();
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

            $share = $this->app->shareAccessService()->publicToken($token,$this->request->hasQuery('download'));

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
            echo $this->renderer->render($kind, $share, $content, $this->request->queryString('Transcripcion') === '1', $this->request->queryString('Extraccion') === '1');
        } catch (ShareException $e) {
            $this->renderError($e);
        } catch (Throwable $e) {
            $this->renderError(new ShareException('No se pudo abrir el enlace compartido.', 500));
        }
    }

    private function handleBookLayout(): void
    {
        try {
            $origin = $this->request->serverString('HTTP_ORIGIN');
            $host = $this->request->serverString('HTTP_HOST');
            $originHost = (string)parse_url($origin, PHP_URL_HOST);
            $originPort = parse_url($origin, PHP_URL_PORT);
            if ($originPort !== null && $originPort !== false) $originHost .= ':' . $originPort;
            if ($host === '' || $originHost === '' || !hash_equals(strtolower($host), strtolower($originHost))) throw new ShareException('Origen no permitido.', 403);
            $token = $this->request->queryString('t');
            if ($token !== '') {
                $share = $this->app->shareAccessService()->publicToken($token);
                $content = $this->app->shareAccessService()->readPublicContent($share);
            } else {
                $key = $this->request->queryString('archivo', $this->request->queryString('key'));
                $userId = $this->authenticatedUserId();
                $content = $this->app->shareAccessService()->privateContent($userId, $key)['contenido'];
                $token = 'private|' . $userId . '|' . $key;
            }
            // Only a server-loaded page is sent to Bedrock; client-supplied text is ignored.
            $pages = (new \ArcadeCloud\Drive\View\ExtractedTextReader())->pages(json_decode((string)$content, true));
            $rawPage = $this->request->postString('page');
            if (!ctype_digit($rawPage)) throw new ShareException('Hoja inválida.', 400);
            $index = (int)$rawPage;
            if (!isset($pages[$index])) throw new ShareException('Hoja no disponible.', 404);
            (new \ArcadeCloud\Drive\Security\UserDirectoryRepository($this->app->db()))->requireById(1);
            $result = (new \ArcadeCloud\Drive\Book\BookAiLayoutService(
                \Config::getBedrockRuntime(),
                \ArcadeCloud\Drive\Activity\ActivityCostRecorder::fromDatabase($this->app->db()),
                new \ArcadeCloud\Drive\Book\BookLayoutStore()
            ))->format($pages[$index]['text'], $token, $this->request->serverString('REMOTE_ADDR'), $index + 1);
            JsonResponse::send($result);
        } catch (ShareException $e) {
            JsonResponse::send(['ok' => false, 'message' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable $e) {
            // No provider errors, credentials, source text or bearer token in public responses.
            JsonResponse::send(['ok' => false, 'message' => 'Formato IA no disponible. Se conserva el texto original.'], 503);
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
                $share = $this->app->shareAccessService()->publicToken($token,$this->request->hasQuery('download'));
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
            echo $this->renderer->render($kind, $share, $content, $this->request->queryString('Transcripcion') === '1', $this->request->queryString('Extraccion') === '1');
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

    private function securityHeaders(): void
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }

    private function renderError(ShareException $e): void
    {
        http_response_code($e->httpStatus());
        header('Content-Type: text/html; charset=UTF-8');
        echo $this->renderer->error($e->getMessage());
    }
}
