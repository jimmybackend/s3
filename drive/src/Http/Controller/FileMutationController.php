<?php
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
