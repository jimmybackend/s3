<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;

final class FolderMutationController extends AbstractJsonController
{
    public function create(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $name = $this->requireNonEmpty($this->request->postString('nueva'), 'Debes indicar el nombre de la carpeta.');
            $session = $this->app->session();
            $routeInput = $this->request->postString('ruta');
            if ($routeInput === '') {
                $routeInput = (string)($_SESSION['ruta_actual'] ?? $this->app->userStoragePath()->rootForUser($userId));
            }
            $route = $this->app->userStoragePath()->normalizeForUser($routeInput, $userId);

            $this->app->s3Manager()->crearCarpeta($route, $name);
            $_SESSION['ruta_actual'] = $route;

            JsonResponse::send([
                'ok' => true,
                'message' => 'Carpeta creada correctamente.',
                'ruta_actual' => $route,
                'nombre' => $name,
            ]);
        } catch (\Throwable $error) {
            JsonResponse::error($error->getMessage(), 400);
        }
    }

    public function delete(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $route = $this->app->userStoragePath()->normalizeForUser(
                $this->requireNonEmpty($this->request->postString('ruta'), 'Falta la ruta de la carpeta.'),
                $userId
            );
            if ($route === $this->app->userStoragePath()->rootForUser($userId)) {
                throw new RuntimeException('No se puede eliminar la carpeta raíz del usuario.');
            }
            $this->app->s3Manager()->eliminarCarpetaCompleta($route);
            JsonResponse::send(['ok' => true, 'message' => 'Carpeta eliminada correctamente.', 'ruta' => $route]);
        } catch (\Throwable $error) {
            JsonResponse::error($error->getMessage(), 400);
        }
    }

    public function move(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $origin = $this->app->userStoragePath()->normalizeForUser(
                $this->requireNonEmpty($this->request->postString('origen'), 'Falta la carpeta origen.'),
                $userId
            );
            $destination = $this->app->userStoragePath()->normalizeForUser(
                $this->requireNonEmpty($this->request->postString('destino'), 'Falta la carpeta destino.'),
                $userId
            );
            if ($origin === $this->app->userStoragePath()->rootForUser($userId)) {
                throw new RuntimeException('No se puede mover la carpeta raíz del usuario.');
            }
            $this->app->s3Manager()->moverCarpeta($origin, $destination);
            JsonResponse::send([
                'ok' => true,
                'message' => 'Carpeta movida correctamente.',
                'origen' => $origin,
                'destino' => $destination,
            ]);
        } catch (\Throwable $error) {
            JsonResponse::error($error->getMessage(), 400);
        }
    }

    public function rename(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $route = $this->app->userStoragePath()->normalizeForUser(
                $this->requireNonEmpty($this->request->postString('ruta'), 'Falta la ruta de la carpeta.'),
                $userId
            );
            $name = $this->requireNonEmpty($this->request->postString('nuevo'), 'Debes indicar el nuevo nombre.');
            if ($route === $this->app->userStoragePath()->rootForUser($userId)) {
                throw new RuntimeException('No se puede renombrar la carpeta raíz del usuario.');
            }
            $this->app->s3Manager()->renombrarCarpeta($route, $name);
            JsonResponse::send(['ok' => true, 'message' => 'Carpeta renombrada correctamente.', 'ruta' => $route, 'nuevo' => $name]);
        } catch (\Throwable $error) {
            JsonResponse::error($error->getMessage(), 400);
        }
    }
}
