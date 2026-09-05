<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class PublicUploadController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function upload(): never
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => 'Método no permitido',
            ], 405);
        }

        $files = $this->request->files();
        $file = $files['file'] ?? null;
        if (!is_array($file)) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => 'Archivo no recibido.',
            ], 400);
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => 'El archivo no fue subido correctamente.',
            ], 400);
        }

        $session = $this->app->session();
        $session->start();
        $userId = $session->isAuthenticated() ? $session->userId() : 0;
        $session->closeWrite();

        $remoteAddress = $this->request->serverString('REMOTE_ADDR', '127.0.0.1');
        $remoteHost = @gethostbyaddr($remoteAddress);
        if (!is_string($remoteHost) || $remoteHost === '') {
            $remoteHost = $remoteAddress;
        }

        try {
            $result = $this->app->publicDropzoneUploadService()->upload(
                $file,
                $this->request->queryString('prefix'),
                [
                    'remote_addr' => $remoteAddress,
                    'user_agent' => $this->request->serverString('HTTP_USER_AGENT', 'desconocido'),
                    'remote_host' => $remoteHost,
                    'accept_language' => $this->request->serverString('HTTP_ACCEPT_LANGUAGE', 'desconocido'),
                    'referer' => $this->request->serverString('HTTP_REFERER', 'ninguno'),
                    'connection' => $this->request->serverString('HTTP_CONNECTION', 'desconocido'),
                    'remote_port' => $this->request->serverString('REMOTE_PORT', 'desconocido'),
                ],
                $userId
            );

            JsonResponse::send($result);
        } catch (Throwable $e) {
            JsonResponse::send([
                'estado' => 'error',
                'mensaje' => $e->getMessage(),
            ], 500);
        }
    }
}
