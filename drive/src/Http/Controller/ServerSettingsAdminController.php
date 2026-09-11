<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Admin\ServerSettingsAdminService;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ServerSettingsAdminController
{
    public function __construct(private DriveApplication $app, private Request $request) {}

    public function api(): void
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated()) JsonResponse::send(['ok' => false, 'error' => 'Autenticación requerida.'], 401);
        if (!$session->isSuperAdmin()) JsonResponse::send(['ok' => false, 'error' => 'Sólo un superusuario puede administrar configuración del servidor.'], 403);

        try {
            $service = new ServerSettingsAdminService($this->app);
            if ($this->request->method() === 'GET') JsonResponse::send($service->state());
            if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);

            $expected = (string)$session->get('server_admin_csrf', '');
            $sent = $this->request->serverString('HTTP_X_SERVER_ADMIN_CSRF');
            if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
                JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido. Recarga el Drive.'], 403);
            }

            $action = strtolower(trim($this->request->postString('action', 'set')));
            if ($action !== 'set') JsonResponse::send(['ok' => false, 'error' => 'Acción no permitida.'], 400);
            JsonResponse::send($service->set(
                $this->request->postString('name'),
                $this->request->postString('value'),
                $this->request->postString('current_password')
            ));
        } catch (InvalidArgumentException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 503);
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo actualizar la configuración administrada del servidor.'], 500);
        }
    }
}
