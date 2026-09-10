<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;

final class FolderMutationController extends AbstractJsonController
{
    public function create(): never
    {
        $started = microtime(true);
        $userId = 0;
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

            $result = $this->app->folderMutationService()->create($userId, $route, $name);
            $session->set('ruta_actual', $route);
            $this->record($userId, 'folder_create', 'S3', [
                's3.put_request' => (int)($result['s3_put_requests'] ?? 1),
            ], $started);

            JsonResponse::send([
                'ok' => true,
                'message' => 'Carpeta creada correctamente.',
                'ruta_actual' => $route,
                'nombre' => $name,
            ]);
        } catch (\Throwable $error) {
            $this->recordFailure($userId, 'folder_create', 'S3', $started);
            JsonResponse::error($error->getMessage(), 400);
        }
    }

    public function delete(): never
    {
        $started = microtime(true);
        $userId = 0;
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
            $this->record($userId, 'folder_delete', 'S3', [
                's3.list_request' => (int)($result['s3_list_requests'] ?? 0),
                's3.delete_request' => (int)($result['s3_delete_requests'] ?? 0),
            ], $started, ['objects' => (int)($result['s3_objects'] ?? 0)]);

            JsonResponse::send([
                'ok' => true,
                'message' => 'Carpeta eliminada correctamente.',
                'ruta' => $route,
                'ruta_actual' => $current,
            ]);
        } catch (\Throwable $error) {
            $this->recordFailure($userId, 'folder_delete', 'S3', $started);
            JsonResponse::error($error->getMessage(), 400);
        }
    }

    public function move(): never
    {
        $started = microtime(true);
        $userId = 0;
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
            $this->record($userId, 'folder_move', 'S3', [
                's3.list_request' => (int)($result['s3_list_requests'] ?? 0),
                's3.copy_request' => (int)($result['s3_copy_requests'] ?? 0),
                's3.delete_request' => (int)($result['s3_delete_requests'] ?? 0),
            ], $started, ['objects' => (int)($result['s3_copy_requests'] ?? 0)]);

            JsonResponse::send([
                'ok' => true,
                'message' => 'Carpeta movida correctamente.',
                'origen' => $origin,
                'destino' => $destination,
            ]);
        } catch (\Throwable $error) {
            $this->recordFailure($userId, 'folder_move', 'S3', $started);
            JsonResponse::error($error->getMessage(), 400);
        }
    }

    public function rename(): never
    {
        $started = microtime(true);
        $userId = 0;
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
            $this->record($userId, 'folder_rename', 'Drive', [
                'drive.no_direct_aws_charge' => 1,
            ], $started);

            JsonResponse::send([
                'ok' => true,
                'message' => 'Carpeta renombrada correctamente.',
                'ruta' => $route,
                'nuevo' => $name,
                'ruta_actual' => $current,
            ]);
        } catch (\Throwable $error) {
            $this->recordFailure($userId, 'folder_rename', 'Drive', $started);
            JsonResponse::error($error->getMessage(), 400);
        }
    }

    private function record(
        int $userId,
        string $action,
        string $service,
        array $units,
        float $started,
        array $metadata = []
    ): void {
        if ($userId <= 0) return;
        $this->recorder()->success(
            $userId,
            $action,
            $service,
            null,
            $units,
            $started,
            $metadata
        );
    }

    private function recordFailure(int $userId, string $action, string $service, float $started): void
    {
        if ($userId <= 0) return;
        $this->recorder()->failure($userId, $action, $service, $started);
    }

    private function recorder(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }
}
