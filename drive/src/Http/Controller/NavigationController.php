<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;

final class NavigationController extends AbstractJsonController
{
    public function updateRoute(): never
    {
        try {
            $userId = $this->guardAuthenticated();

            $input = $this->request->postString('ruta');
            if ($input === '') {
                $input = $this->request->postString('rutaNueva');
            }

            if ($input === '') {
                JsonResponse::send([
                    'ok' => false,
                    'mensaje' => 'Ruta vacía',
                ], 422);
            }

            $route = $this->app->userStoragePath()->normalizeForUser($input, $userId);
            $this->app->session()->set('ruta_actual', $route);

            JsonResponse::send([
                'ok' => true,
                'ruta' => $route,
            ]);
        } catch (\Throwable $e) {
            JsonResponse::send([
                'ok' => false,
                'mensaje' => $e->getMessage(),
            ], 500);
        }
    }
}
