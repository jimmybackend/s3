<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Admin\RepositoryUpdateService;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RepositoryUpdateController
{
    public function __construct(private DriveApplication $app, private Request $request) {}

    public function api(): void
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated()) JsonResponse::send(['ok' => false, 'error' => 'Autenticación requerida.'], 401);
        if (!$session->isSuperAdmin()) JsonResponse::send(['ok' => false, 'error' => 'Sólo un superusuario puede administrar actualizaciones.'], 403);
        if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);

        $expectedCsrf = (string)$session->get('server_admin_csrf', '');
        $sentCsrf = $this->request->serverString('HTTP_X_SERVER_ADMIN_CSRF');
        if ($expectedCsrf === '' || $sentCsrf === '' || !hash_equals($expectedCsrf, $sentCsrf)) {
            JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido. Recarga el Drive.'], 403);
        }

        try {
            $service = new RepositoryUpdateService($this->app);
            $action = strtolower(trim($this->request->postString('action', 'check')));
            if ($action === 'check') JsonResponse::send($service->check());
            if ($action === 'update') {
                JsonResponse::send($service->update(
                    $this->request->postString('expected_remote_sha'),
                    $this->request->postString('current_password')
                ));
            }
            JsonResponse::send(['ok' => false, 'error' => 'Acción no permitida.'], 400);
        } catch (InvalidArgumentException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 503);
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo administrar la actualización del repositorio.'], 500);
        }
    }
}
