<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationNodeAdminService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class FederationNodeAdminController
{
    public function __construct(private DriveApplication $app, private Request $request) {}

    public function api(): void
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated()) JsonResponse::send(['ok' => false, 'error' => 'Autenticación requerida.'], 401);
        if (!$session->isSuperAdmin()) JsonResponse::send(['ok' => false, 'error' => 'Sólo un superusuario puede administrar la identidad del nodo.'], 403);

        try {
            $service = new FederationNodeAdminService($this->app);
            if ($this->request->method() === 'GET') JsonResponse::send($service->state());
            if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);

            $expectedToken = (string)$session->get('federation_provider_csrf', '');
            $sentToken = $this->request->serverString('HTTP_X_FEDERATION_CSRF');
            if ($expectedToken === '' || $sentToken === '' || !hash_equals($expectedToken, $sentToken)) {
                JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido. Recarga el Drive.'], 403);
            }

            $action = strtolower(trim($this->request->postString('action', 'rename')));
            if ($action === 'create') JsonResponse::send($service->createNode($this->request->postString('node_name')));
            if ($action === 'rename') JsonResponse::send($service->renameNode($this->request->postString('node_name')));
            JsonResponse::send(['ok' => false, 'error' => 'Acción administrativa de nodo no permitida.'], 400);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo administrar la identidad del nodo.'], 500);
        }
    }
}
