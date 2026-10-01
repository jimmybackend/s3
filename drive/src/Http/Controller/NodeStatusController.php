<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use ArcadeCloud\Drive\Admin\ServerMaintenanceJobStore;
use ArcadeCloud\Drive\Admin\ServerMaintenanceService;
use ArcadeCloud\Drive\Admin\NodeServiceControlService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\System\NodeRuntimeStatusService;
use RuntimeException;

final class NodeStatusController extends AbstractJsonController
{
    public function dispatch(): never
    {
        try {
            $userId = $this->guardAuthenticated();

            if ($this->request->method() === 'GET') {
                $status = new NodeRuntimeStatusService($this->app);
                $local = $status->local(dirname(__DIR__, 3));
                JsonResponse::send([
                    'ok' => true,
                    // node remains the legacy-compatible local payload.
                    'node' => $local,
                    'local' => $local,
                    'fastdrive' => $status->fastDrive(),
                ]);
            }

            $this->requirePost();
            $session = $this->app->session();
            if (!$session->isSuperAdmin()) {
                JsonResponse::send(['ok' => false, 'error' => 'Sólo el superusuario puede programar mantenimiento.'], 403);
            }

            $expected = (string)$session->get('server_admin_csrf', '');
            $sent = $this->request->serverString('HTTP_X_SERVER_ADMIN_CSRF');
            if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
                JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido. Recarga ArcadeCloud OS.'], 403);
            }

            $action = $this->request->postString('action');
            if ($action === 'component-action') {
                $result = (new NodeServiceControlService($this->app))->execute(
                    $this->request->postString('component'),
                    $this->request->postString('operation'),
                    $this->request->postRawString('access_password')
                );
                JsonResponse::send(['ok' => true] + $result, 202);
            }
            if (!in_array($action, ['memory-clear', 'disk-clean'], true)) {
                throw new RuntimeException('Acción de nodo no permitida.');
            }

            $maintenance = new ServerMaintenanceService(
                $this->app,
                new ServerMaintenanceJobStore(),
                new PrivilegedServerHelper()
            );
            $password = $this->request->postRawString('access_password');
            $result = $action === 'disk-clean'
                ? $maintenance->queueDiskCleanup($userId, $password)
                : $maintenance->queueMemoryClear($userId, $password);

            $waiting = (int)($result['blocking']['active'] ?? 0) > 0;
            JsonResponse::send([
                'ok' => true,
                'message' => $action === 'disk-clean'
                    ? ($waiting
                        ? 'La limpieza de disco quedó en Tareas y esperará a que terminen los procesos activos.'
                        : 'La limpieza segura de disco quedó programada.')
                    : ($waiting
                        ? 'La limpieza quedó en Tareas y esperará a que terminen los procesos activos.'
                        : 'La limpieza de memoria quedó programada.'),
                'maintenance' => $result,
                'node' => $this->snapshot(),
            ], 202);
        } catch (RuntimeException $error) {
            JsonResponse::send(['ok' => false, 'error' => $error->getMessage()], 400);
        } catch (\Throwable $error) {
            error_log('[ArcadeCloud node-status] ' . $error->getMessage());
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo consultar o mantener el nodo.'], 500);
        }
    }

    private function snapshot(): array
    {
        // NodeRuntimeStatusService vuelve a medir mediante NodeCapabilityService.
        return (new NodeRuntimeStatusService($this->app))->local(dirname(__DIR__, 3));
    }
}
