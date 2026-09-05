<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class UploadController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function handle(): never
    {
        $session = $this->app->session();
        $session->start();

        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::send(['ok' => false, 'error' => 'Sesión inválida.'], 401);
        }

        $action = $this->request->queryString(
            'action',
            $this->request->postString('action')
        );
        $mode = $this->request->queryString(
            'mode',
            $this->request->postString('mode')
        );

        if ($action === '' || $mode === '') {
            JsonResponse::send(['ok' => false, 'error' => 'Faltan parámetros action/mode'], 400);
        }

        try {
            $req = array_merge($this->request->allQuery(), $this->request->allPost());
            $req['_files'] = $this->request->files();
            $req['_user_id'] = $session->userId();
            $req['_usuario'] = $session->userName();
            $req['_remote_addr'] = $this->request->serverString('REMOTE_ADDR', '0.0.0.0');
            $req['_user_agent'] = $this->request->serverString('HTTP_USER_AGENT', 'desconocido');
            $req['_referer'] = $this->request->serverString('HTTP_REFERER', 'ninguno');

            if ($action === 'init') {
                $requestedRoute = trim((string)($req['ruta_objetivo'] ?? ''));
                if ($requestedRoute === '') {
                    JsonResponse::send([
                        'ok' => false,
                        'error' => 'Falta ruta_objetivo. La subida debe fijar su destino al iniciar.',
                    ], 422);
                }

                $req['ruta_objetivo'] = $this->app->uploadDestinationService()->resolve(
                    $session->userId(),
                    $requestedRoute
                );
            }

            $uploader = $this->app->uploadFactory()->make($mode);

            $keepSessionOpen = $mode === 'local_put'
                && in_array($action, ['init', 'part', 'complete'], true);

            if (!$keepSessionOpen) {
                $session->closeWrite();
            }

            $result = match ($action) {
                'init' => $uploader->init($req),
                'part' => $uploader->part($req),
                'complete' => $uploader->complete($req),
                default => throw new \RuntimeException('Acción inválida'),
            };

            if ($keepSessionOpen) {
                $session->closeWrite();
            }

            JsonResponse::send(['ok' => true] + $result);
        } catch (Throwable $e) {
            $session->closeWrite();
            JsonResponse::send([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
