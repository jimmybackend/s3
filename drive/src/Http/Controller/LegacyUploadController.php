<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class LegacyUploadController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function single(): never
    {
        $session = $this->authenticatedSession();
        if ($this->request->method() !== 'POST') {
            JsonResponse::send(['estado' => 'error', 'mensaje' => 'Método no permitido'], 405);
        }

        $userId = $session->userId();
        $started = microtime(true);
        try {
            $files = $this->request->files();
            $file = $files['file'] ?? null;
            if (!is_array($file)) throw new \RuntimeException('No se recibió archivo');
            if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \RuntimeException('Error al subir el archivo');

            $tmpPath = (string)($file['tmp_name'] ?? '');
            if ($tmpPath === '' || !is_file($tmpPath)) throw new \RuntimeException('Archivo temporal inválido');

            $originalName = basename((string)($file['name'] ?? ''));
            $fileSize = (int)(filesize($tmpPath) ?: 0);
            $mimeType = (string)(mime_content_type($tmpPath) ?: 'application/octet-stream');
            $route = $this->currentRoute($userId);

            $result = $this->app->singleUploadService()->upload(
                $tmpPath,
                $originalName,
                $route,
                $userId,
                $mimeType,
                $fileSize,
                $this->request->serverString('REMOTE_ADDR', 'unknown'),
                $this->request->serverString('HTTP_USER_AGENT', 'unknown')
            );

            $this->activity()->success($userId, 'upload', 'S3', (int)($result['id'] ?? 0), [
                's3.put_request' => 1,
                's3.storage_bytes_delta' => $fileSize,
            ], $started, ['mode' => 'legacy_single', 'size_bytes' => $fileSize]);

            JsonResponse::send(['estado' => 'ok', 'mensaje' => 'Archivo subido correctamente', 'data' => $result]);
        } catch (Throwable $e) {
            $this->activity()->failure($userId, 'upload', 'S3', $started, ['mode' => 'legacy_single']);
            JsonResponse::send(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    public function multi(): never
    {
        $session = $this->authenticatedSession();
        if ($this->request->method() !== 'POST') {
            JsonResponse::send(['estado' => 'error', 'mensaje' => 'Método no permitido'], 405);
        }

        $files = $this->request->files();
        if (empty($files['file'])) {
            JsonResponse::send(['estado' => 'error', 'mensaje' => 'No se recibieron archivos válidos.'], 400);
        }

        $userId = $session->userId();
        $started = microtime(true);
        try {
            $req = array_merge($this->request->allQuery(), $this->request->allPost());
            $req['_files'] = $files;
            $req['_user_id'] = $userId;
            $req['_usuario'] = $session->userName();
            $req['_remote_addr'] = $this->request->serverString('REMOTE_ADDR', '0.0.0.0');
            $req['_user_agent'] = $this->request->serverString('HTTP_USER_AGENT', 'desconocido');
            $req['_referer'] = $this->request->serverString('HTTP_REFERER', 'ninguno');
            $req['ruta_objetivo'] = $this->currentRoute($userId);

            $result = $this->app->uploadFactory()->make('dropbox')->init($req);
            $rows = is_array($result['resultados'] ?? null) ? $result['resultados'] : [];
            [$successful, $size] = $this->successfulUploadStats($files['file'], $rows);
            if ($successful > 0) {
                $this->activity()->success($userId, 'upload', 'S3', null, [
                    's3.put_request' => $successful,
                    's3.storage_bytes_delta' => $size,
                ], $started, ['mode' => 'legacy_multi', 'items' => $successful, 'size_bytes' => $size]);
            }
            JsonResponse::send($result);
        } catch (Throwable $e) {
            $this->activity()->failure($userId, 'upload', 'S3', $started, ['mode' => 'legacy_multi']);
            JsonResponse::send(['estado' => 'error', 'mensaje' => $e->getMessage()], 500);
        }
    }

    private function successfulUploadStats(array $file, array $rows): array
    {
        $sizes = $file['size'] ?? [];
        if (!is_array($sizes)) $sizes = [$sizes];
        $count = 0;
        $bytes = 0;
        foreach ($rows as $index => $row) {
            if (!is_array($row) || ($row['estado'] ?? '') !== 'ok') continue;
            $count++;
            $bytes += max(0, (int)($sizes[$index] ?? 0));
        }
        return [$count, $bytes];
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }

    private function authenticatedSession(): \ArcadeCloud\Drive\Security\SessionManager
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::send(['estado' => 'error', 'mensaje' => 'Acceso denegado'], 403);
        }
        return $session;
    }

    private function currentRoute(int $userId): string
    {
        return $this->app->userStoragePath()->normalizeForUser((string)$this->app->session()->get('ruta_actual', ''), $userId);
    }
}
