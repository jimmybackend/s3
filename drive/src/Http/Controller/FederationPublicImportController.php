<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationPublicImportService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class FederationPublicImportController
{
    public function __construct(private DriveApplication $app, private Request $request)
    {
    }

    public function api(): never
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::send(['ok' => false, 'error' => 'Autenticación requerida.'], 401);
        }

        $userId = $session->userId();
        $service = new FederationPublicImportService($this->app);

        try {
            if ($this->request->method() === 'GET') {
                $csrf = (string)$session->get('federation_public_import_csrf', '');
                if ($csrf === '') {
                    $csrf = bin2hex(random_bytes(24));
                    $session->set('federation_public_import_csrf', $csrf);
                }
                $resourceId = trim($this->request->queryString('resource_id'));
                $payload = ['ok' => true, 'csrf' => $csrf];
                if ($resourceId !== '') $payload += $service->state($userId, $resourceId);
                JsonResponse::send($payload);
            }

            if ($this->request->method() !== 'POST') {
                JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
            }

            $expected = (string)$session->get('federation_public_import_csrf', '');
            $sent = $this->request->serverString('HTTP_X_FEDERATION_PUBLIC_IMPORT_CSRF');
            if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
                JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido.'], 403);
            }

            $action = strtolower(trim($this->request->postString('action', 'queue')));
            if ($action !== 'queue') throw new FederationException('Acción de importación pública inválida.', 400);

            JsonResponse::send($service->queue(
                $userId,
                $this->request->postString('resource_id')
            ));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable $e) {
            error_log('[FederationCloud public import] ' . $e->getMessage());
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo importar el recurso público.'], 500);
        }
    }
}
