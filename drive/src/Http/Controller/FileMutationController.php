<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;

final class FileMutationController extends AbstractJsonController
{
    public function deleteOne(): never
    {
        $userId = 0; $started = microtime(true);
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $ref = $this->request->postInt('file_id');
            if ($ref <= 0) $ref = $this->request->postString('archivo');
            if ($ref === '' || $ref === 0) throw new RuntimeException('Falta la referencia del archivo');

            $result = $this->app->fileMutationService()->delete($userId, $ref);
            $this->activity()->success($userId, 'delete', 'S3', (int)($result['id'] ?? 0), [
                's3.delete_request' => 1,
            ], $started);

            JsonResponse::send(['ok'=>true,'estado'=>'ok','mensaje'=>'Archivo eliminado correctamente','data'=>$result]);
        } catch (\Throwable $error) {
            if ($userId > 0) $this->activity()->failure($userId, 'delete', 'S3', $started);
            $this->fail($error);
        }
    }

    public function deleteMany(): never
    {
        $userId = 0; $started = microtime(true);
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $keys = $this->keysFromRequest();
            if (!$keys) throw new RuntimeException('No hay archivos seleccionados');

            $result = $this->app->fileMutationService()->deleteMany($userId, $keys);
            $total = (int)($result['total'] ?? count($keys));
            $this->activity()->success($userId, 'delete', 'S3', null, [
                's3.delete_request' => $total,
            ], $started, ['items' => $total]);

            JsonResponse::send(['ok'=>true,'estado'=>'ok','mensaje'=>'Archivos eliminados correctamente','data'=>$result]);
        } catch (\Throwable $error) {
            if ($userId > 0) $this->activity()->failure($userId, 'delete', 'S3', $started);
            $this->fail($error);
        }
    }

    public function move(): never
    {
        $userId = 0; $started = microtime(true);
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $route = $this->request->postString('nueva_ruta');
            if ($route === '') $route = $this->request->postString('ruta_destino');
            $route = $this->app->userStoragePath()->normalizeForUser(
                $this->requireNonEmpty($route, 'Falta la ruta destino'),
                $userId
            );

            $fileId = $this->request->postInt('file_id');
            if ($fileId > 0) {
                $result = $this->app->fileMutationService()->move($userId, $fileId, $route);
                $this->activity()->success($userId, 'move', 'S3', (int)($result['id'] ?? $fileId), [
                    's3.copy_request' => 1,
                    's3.delete_request' => 1,
                ], $started);
                JsonResponse::send(['ok'=>true,'estado'=>'ok','mensaje'=>'Archivo movido correctamente','data'=>$result]);
            }

            $keys = $this->keysFromRequest();
            if (!$keys) {
                $single = $this->request->postString('archivo');
                if ($single !== '') $keys = [$single];
            }
            if (!$keys) throw new RuntimeException('No hay archivos seleccionados');

            $result = $this->app->fileMutationService()->moveMany($userId, $keys, $route);
            $total = (int)($result['total'] ?? count($keys));
            $this->activity()->success($userId, 'move', 'S3', null, [
                's3.copy_request' => $total,
                's3.delete_request' => $total,
            ], $started, ['items' => $total]);

            JsonResponse::send(['ok'=>true,'estado'=>'ok','mensaje'=>$total===1?'Archivo movido correctamente':'Archivos movidos correctamente','data'=>$result]);
        } catch (\Throwable $error) {
            if ($userId > 0) $this->activity()->failure($userId, 'move', 'S3', $started);
            $this->fail($error);
        }
    }

    public function moveMany(): never { $this->move(); }

    public function rename(): never
    {
        $userId = 0; $started = microtime(true);
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $key = $this->requireNonEmpty($this->request->postString('key'), 'Falta la clave del archivo.');
            $name = $this->requireNonEmpty($this->request->postString('nombre_nuevo'), 'Falta el nuevo nombre del archivo.');
            $result = $this->app->fileMutationService()->rename($userId, $key, $name);
            $this->activity()->success($userId, 'rename', 'Drive', (int)($result['id'] ?? 0), ['drive.no_direct_aws_charge' => 1], $started);
            JsonResponse::send(['ok'=>true,'estado'=>'ok','mensaje'=>'Archivo renombrado correctamente','data'=>$result]);
        } catch (\Throwable $error) {
            if ($userId > 0) $this->activity()->failure($userId, 'rename', 'Drive', $started);
            $this->fail($error);
        }
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }
}
