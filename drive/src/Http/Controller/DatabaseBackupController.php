<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Admin\DatabaseBackupService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Security\SuperAdminReauthenticationService;
use RuntimeException;
use Throwable;

final class DatabaseBackupController extends AbstractJsonController
{
    public function dispatch(): never
    {
        try {
            $userId = $this->guardAuthenticated();
            $this->requirePost();

            $session = $this->app->session();
            if (!$session->isSuperAdmin()) {
                JsonResponse::send(['ok' => false, 'error' => 'Acceso reservado al superadministrador.'], 403);
            }

            $expected = (string)$session->get('server_admin_csrf', '');
            $sent = $this->request->serverString('HTTP_X_SERVER_ADMIN_CSRF');
            if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
                JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido. Recarga ArcadeCloud OS.'], 403);
            }

            (new SuperAdminReauthenticationService($this->app))->requireRecent(
                $this->request->postRawString('access_password')
            );

            $backup = (new DatabaseBackupService($this->app))->createForSuperAdmin($userId);

            JsonResponse::send([
                'ok' => true,
                'message' => 'Respaldo creado correctamente.',
                'backup' => $backup,
            ], 201);
        } catch (\InvalidArgumentException|RuntimeException $error) {
            JsonResponse::send(['ok' => false, 'error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            error_log('[ArcadeCloud database-backup] ' . $error->getMessage());
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo crear el respaldo de la base de datos.'], 500);
        }
    }
}
