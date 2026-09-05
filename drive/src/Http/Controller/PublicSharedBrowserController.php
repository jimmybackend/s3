<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\PublicSharedPageRenderer;
use RuntimeException;
use Throwable;

final class PublicSharedBrowserController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request,
        private ?PublicSharedPageRenderer $renderer = null
    ) {
        $this->renderer ??= new PublicSharedPageRenderer();
    }

    public function page(): void
    {
        try {
            $method = $this->request->method();
            $route = $this->request->queryString(
                'ruta',
                $this->request->postString('ruta')
            );

            if ($method === 'POST') {
                $folderName = $this->request->postString('nueva');
                if ($folderName !== '') {
                    $route = $this->app
                        ->publicSharedBrowserService()
                        ->createFolder($route, $folderName);

                    header('Location: ?ruta=' . rawurlencode($route), true, 303);
                    exit;
                }
            } elseif ($method !== 'GET') {
                throw new RuntimeException('Método no permitido.');
            }

            $state = $this->app->publicSharedBrowserService()->browse($route);

            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderer->render($state);
        } catch (RuntimeException $e) {
            $this->renderError($e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->renderError('No se pudo abrir la zona compartida.', 500);
        }
    }

    private function renderError(string $message, int $status): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');

        try {
            $state = $this->app->publicSharedBrowserService()->browse('');
        } catch (Throwable $ignored) {
            $state = [
                'route' => '',
                'prefix' => '',
                'parent_route' => '',
                'folders' => [],
                'files' => [],
            ];
        }

        echo $this->renderer->render($state, $message);
    }
}
