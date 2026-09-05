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
                $routeInput = (string)$session->get('ruta_actual', $this->app->userStoragePath()->rootForUser($userId));
            }
            $route = $this->app->userStoragePath()->normalizeForUser($routeInput, $userId);

            $this->app->folderMutationService()->create($userId, $route, $name);
            $session->set('ruta_actual', $route);

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
            $paths = $this->app->userStoragePath();
            $root = $paths->rootForUser($userId);
            $route = $paths->normalizeForUser(
                $this->requireNonEmpty($this->request->postString('ruta'), 'Falta la ruta de la carpeta.'),
                $userId
            );
            if ($route === $root) {
                throw new RuntimeException('No se puede eliminar la carpeta raíz del usuario.');
            }

            $result = $this->app->folderMutationService()->delete($userId, $route);
            $session = $this->app->session();
            $current = $paths->normalizeForUser((string)$session->get('ruta_actual', $root), $userId);
            if (str_starts_with($current, $route)) {
                $current = $paths->normalizeForUser((string)($result['parent'] ?? $root), $userId);
                $session->set('ruta_actual', $current);
            }

            JsonResponse::send([
                'ok' => true,
                'message' => 'Carpeta eliminada correctamente.',
                'ruta' => $route,
                'ruta_actual' => $current,
            ]);
        } catch (\Throwable $error) {
            JsonResponse::error($error->getMessage(), 400);
        }
    }

    public function move(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $paths = $this->app->userStoragePath();
            $root = $paths->rootForUser($userId);
            $origin = $paths->normalizeForUser(
                $this->requireNonEmpty($this->request->postString('origen'), 'Falta la carpeta origen.'),
                $userId
            );
            $destination = $paths->normalizeForUser(
                $this->requireNonEmpty($this->request->postString('destino'), 'Falta la carpeta destino.'),
                $userId
            );
            if ($origin === $root) {
                throw new RuntimeException('No se puede mover la carpeta raíz del usuario.');
            }

            $result = $this->app->folderMutationService()->move($userId, $origin, $destination);
            $final = (string)$result['destino'];
            $session = $this->app->session();
            $current = $paths->normalizeForUser((string)$session->get('ruta_actual', $root), $userId);
            if (str_starts_with($current, $origin)) {
                $session->set('ruta_actual', $final . substr($current, strlen($origin)));
            }

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
            $paths = $this->app->userStoragePath();
            $root = $paths->rootForUser($userId);
            $route = $paths->normalizeForUser(
                $this->requireNonEmpty($this->request->postString('ruta'), 'Falta la ruta de la carpeta.'),
                $userId
            );
            $name = $this->requireNonEmpty($this->request->postString('nuevo'), 'Debes indicar el nuevo nombre.');
            if ($route === $root) {
                throw new RuntimeException('No se puede renombrar la carpeta raíz del usuario.');
            }

            $this->app->folderMutationService()->rename($userId, $route, $name);
            $current = $paths->normalizeForUser(
                (string)$this->app->session()->get('ruta_actual', $root),
                $userId
            );

            JsonResponse::send([
                'ok' => true,
                'message' => 'Carpeta renombrada correctamente.',
                'ruta' => $route,
                'nuevo' => $name,
                'ruta_actual' => $current,
            ]);
        } catch (\Throwable $error) {
            JsonResponse::error($error->getMessage(), 400);
        }
    }
}
