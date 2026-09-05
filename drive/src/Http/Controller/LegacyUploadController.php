<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

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
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => 'Método no permitido',
            ], 405);
        }

        try {
            $files = $this->request->files();
            $file = $files['file'] ?? null;
            if (!is_array($file)) {
                throw new \RuntimeException('No se recibió archivo');
            }

            if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Error al subir el archivo');
            }

            $tmpPath = (string)($file['tmp_name'] ?? '');
            if ($tmpPath === '' || !is_file($tmpPath)) {
                throw new \RuntimeException('Archivo temporal inválido');
            }

            $originalName = basename((string)($file['name'] ?? ''));
            $fileSize = (int)(filesize($tmpPath) ?: 0);
            $mimeType = (string)(mime_content_type($tmpPath) ?: 'application/octet-stream');
            $userId = $session->userId();
            $route = $this->currentRoute($userId);

            $result = $this->app->s3Manager()->uploadFile(
                $tmpPath,
                $originalName,
                $route,
                $userId,
                $mimeType,
                $fileSize
            );

            JsonResponse::send([
                'estado' => 'ok',
                'mensaje' => 'Archivo subido correctamente',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => $e->getMessage(),
            ], 500);
        }
    }

    public function multi(): never
    {
        $session = $this->authenticatedSession();

        if ($this->request->method() !== 'POST') {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => 'Método no permitido',
            ], 405);
        }

        $files = $this->request->files();
        if (empty($files['file'])) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => 'No se recibieron archivos válidos.',
            ], 400);
        }

        try {
            $userId = $session->userId();
            $req = array_merge($this->request->allQuery(), $this->request->allPost());
            $req['_files'] = $files;
            $req['_user_id'] = $userId;
            $req['_usuario'] = $session->userName();
            $req['_remote_addr'] = $this->request->serverString('REMOTE_ADDR', '0.0.0.0');
            $req['_user_agent'] = $this->request->serverString('HTTP_USER_AGENT', 'desconocido');
            $req['_referer'] = $this->request->serverString('HTTP_REFERER', 'ninguno');
            $req['ruta_objetivo'] = $this->currentRoute($userId);

            $result = $this->app->uploadFactory()->make('dropbox')->init($req);
            JsonResponse::send($result);
        } catch (Throwable $e) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => $e->getMessage(),
            ], 500);
        }
    }

    private function authenticatedSession(): \ArcadeCloud\Drive\Security\SessionManager
    {
        $session = $this->app->session();
        $session->start();

        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => 'Acceso denegado',
            ], 403);
        }

        return $session;
    }

    private function currentRoute(int $userId): string
    {
        return $this->app->userStoragePath()->normalizeForUser(
            (string)$this->app->session()->get('ruta_actual', ''),
            $userId
        );
    }
}
