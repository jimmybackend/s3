<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use Throwable;

final class MediaPlaylistController extends AbstractJsonController
{
    public function list(): never
    {
        if ($this->request->method() !== 'GET') {
            JsonResponse::error('Método no permitido.', 405);
        }

        try {
            $userId = $this->guardAuthenticated();
            $route = $this->request->queryString(
                'ruta',
                (string)$this->app->session()->get('ruta_actual', '')
            );

            JsonResponse::send(
                $this->app->mediaPlaylistService()->build($userId, $route)
            );
        } catch (Throwable $e) {
            JsonResponse::send([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
