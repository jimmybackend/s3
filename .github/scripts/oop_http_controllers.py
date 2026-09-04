from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DRIVE = ROOT / 'drive'
SRC = DRIVE / 'src'


def write(path: Path, content: str):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8')

write(SRC / 'Http/Request.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http;

use RuntimeException;

final class Request
{
    public function __construct(
        private array $server,
        private array $query,
        private array $post,
        private array $files = []
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_FILES);
    }

    public function method(): string
    {
        return strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function requireMethod(string $method): void
    {
        if ($this->method() !== strtoupper($method)) {
            throw new RuntimeException('Método no permitido.');
        }
    }

    public function postString(string $name, string $default = ''): string
    {
        $value = $this->post[$name] ?? $default;
        if (is_array($value) || is_object($value)) {
            return $default;
        }
        return trim((string)$value);
    }

    public function postInt(string $name, int $default = 0): int
    {
        $value = $this->post[$name] ?? $default;
        return is_scalar($value) ? (int)$value : $default;
    }

    public function postArray(string $name): array
    {
        $value = $this->post[$name] ?? [];
        return is_array($value) ? $value : [];
    }

    public function postJsonArray(string $name): array
    {
        $raw = $this->post[$name] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_scalar($raw)) {
            return [];
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function queryString(string $name, string $default = ''): string
    {
        $value = $this->query[$name] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    public function hasPost(string $name): bool
    {
        return array_key_exists($name, $this->post);
    }

    public function files(): array
    {
        return $this->files;
    }
}
''')

write(SRC / 'Http/Controller/AbstractJsonController.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use RuntimeException;

abstract class AbstractJsonController
{
    public function __construct(
        protected DriveApplication $app,
        protected Request $request
    ) {
    }

    protected function guardAuthenticated(): int
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::error('Sesión inválida.', 401);
        }
        return $session->userId();
    }

    protected function requirePost(): void
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::error('Método no permitido.', 405, [
                'estado' => 'error',
                'mensaje' => 'Método no permitido'
            ]);
        }
    }

    protected function keysFromRequest(): array
    {
        $keys = $this->request->postArray('archivos');
        if (!$keys) {
            $keys = $this->request->postJsonArray('archivos_json');
        }
        return array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            $keys
        ))));
    }

    protected function fail(\Throwable $error, int $status = 500): never
    {
        JsonResponse::send([
            'ok' => false,
            'estado' => 'error',
            'mensaje' => $error->getMessage(),
            'error' => $error->getMessage(),
        ], $status);
    }

    protected function requireNonEmpty(string $value, string $message): string
    {
        if ($value === '') {
            throw new RuntimeException($message);
        }
        return $value;
    }
}
''')

write(SRC / 'Http/Controller/FileMutationController.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;

final class FileMutationController extends AbstractJsonController
{
    public function deleteOne(): never
    {
        try {
            $this->requirePost();
            $this->guardAuthenticated();
            $ref = $this->request->postInt('file_id');
            if ($ref <= 0) {
                $ref = $this->request->postString('archivo');
            }
            if ($ref === '' || $ref === 0) {
                throw new RuntimeException('Falta la referencia del archivo');
            }

            JsonResponse::send([
                'ok' => true,
                'estado' => 'ok',
                'mensaje' => 'Archivo eliminado correctamente',
                'data' => $this->app->s3Manager()->deleteFile($ref),
            ]);
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }

    public function deleteMany(): never
    {
        try {
            $this->requirePost();
            $this->guardAuthenticated();
            $keys = $this->keysFromRequest();
            if (!$keys) {
                throw new RuntimeException('No hay archivos seleccionados');
            }

            JsonResponse::send([
                'ok' => true,
                'estado' => 'ok',
                'mensaje' => 'Archivos eliminados correctamente',
                'data' => $this->app->s3Manager()->deleteMultiple($keys),
            ]);
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }

    public function move(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $route = $this->request->postString('nueva_ruta');
            if ($route === '') {
                $route = $this->request->postString('ruta_destino');
            }
            $route = $this->app->userStoragePath()->normalizeForUser(
                $this->requireNonEmpty($route, 'Falta la ruta destino'),
                $userId
            );

            $fileId = $this->request->postInt('file_id');
            if ($fileId > 0) {
                JsonResponse::send([
                    'ok' => true,
                    'estado' => 'ok',
                    'mensaje' => 'Archivo movido correctamente',
                    'data' => $this->app->s3Manager()->moveFile($fileId, $route),
                ]);
            }

            $keys = $this->keysFromRequest();
            if (!$keys) {
                $single = $this->request->postString('archivo');
                if ($single !== '') {
                    $keys = [$single];
                }
            }
            if (!$keys) {
                throw new RuntimeException('No hay archivos seleccionados');
            }

            JsonResponse::send([
                'ok' => true,
                'estado' => 'ok',
                'mensaje' => count($keys) === 1 ? 'Archivo movido correctamente' : 'Archivos movidos correctamente',
                'data' => $this->app->s3Manager()->moveMultiple($keys, $route),
            ]);
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }

    public function moveMany(): never
    {
        $this->move();
    }

    public function rename(): never
    {
        try {
            $this->requirePost();
            $this->guardAuthenticated();
            $key = $this->requireNonEmpty($this->request->postString('key'), 'Falta la clave del archivo.');
            $name = $this->requireNonEmpty($this->request->postString('nombre_nuevo'), 'Falta el nuevo nombre del archivo.');

            JsonResponse::send([
                'ok' => true,
                'estado' => 'ok',
                'mensaje' => 'Archivo renombrado correctamente',
                'data' => $this->app->s3Manager()->renameFile($key, $name),
            ]);
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }
}
''')

write(SRC / 'Http/Controller/FolderMutationController.php', r'''<?php
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
''')

# Entrypoints: solo bootstrap + objeto + acción.
entrypoints = {
    'eliminar_archivo.php': ('FileMutationController', 'deleteOne'),
    'delete_multiple.php': ('FileMutationController', 'deleteMany'),
    'mover_archivo.php': ('FileMutationController', 'move'),
    'move_multiple.php': ('FileMutationController', 'moveMany'),
    'renombrar_archivo.php': ('FileMutationController', 'rename'),
    'crear_carpeta.php': ('FolderMutationController', 'create'),
    'eliminar_carpeta.php': ('FolderMutationController', 'delete'),
    'mover_carpeta.php': ('FolderMutationController', 'move'),
    'renombrar_carpeta.php': ('FolderMutationController', 'rename'),
}

for filename, (controller, method) in entrypoints.items():
    write(DRIVE / filename, f'''<?php\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/app_bootstrap.php';\n\n(new \\ArcadeCloud\\Drive\\Http\\Controller\\{controller}(\n    \\ArcadeCloud\\Drive\\Core\\ApplicationKernel::app(),\n    \\ArcadeCloud\\Drive\\Http\\Request::fromGlobals()\n))->{method}();\n''')

print('HTTP mutation controllers migrated')
