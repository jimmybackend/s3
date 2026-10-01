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
                if (!$this->app->session()->isSuperAdmin()) {
                    $local = $this->publicSnapshot($local);
                }
                JsonResponse::send([
                    'ok' => true,
                    // Mi nodo is deliberately scoped to the server handling this request.
                    // FederationCloud owns remote-node discovery; this endpoint never accepts
                    // or resolves a remote node target.
                    'scope' => 'local',
                    'node' => $local,
                    'local' => $local,
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

    /**
     * Node status is useful to every signed-in user, but process, service, network,
     * database and host identity details are administration data. Keep this
     * allow-list here (rather than relying on the OS renderer) so a normal user
     * cannot obtain those fields by calling the JSON endpoint directly.
     */
    private function publicSnapshot(array $node): array
    {
        $resources = is_array($node['resources'] ?? null) ? $node['resources'] : [];
        $federation = is_array($node['federation'] ?? null) ? $node['federation'] : [];
        $health = is_array($node['health'] ?? null) ? $node['health'] : [];
        $identity = is_array($node['identity'] ?? null) ? $node['identity'] : [];

        return [
            'scope' => 'local',
            'generated_at' => (string)($node['generated_at'] ?? ''),
            'available' => (bool)($node['available'] ?? true),
            'identity' => [
                'display_name' => (string)($identity['display_name'] ?? ''),
                'node_name' => (string)($identity['node_name'] ?? ''),
                'role' => (string)($identity['role'] ?? ''),
            ],
            'health' => [
                'state' => (string)($health['state'] ?? 'neutral'),
                'label' => (string)($health['label'] ?? 'No disponible'),
            ],
            'resources' => [
                'vcpu' => (int)($resources['vcpu'] ?? $node['vcpu'] ?? 0),
                'load_average' => array_slice((array)($resources['load_average'] ?? $node['load_average'] ?? []), 0, 3),
                'memory' => (array)($resources['memory'] ?? []),
                'disk' => (array)($resources['disk'] ?? []),
            ],
            'federation' => [
                'enabled' => (bool)($federation['enabled'] ?? false),
                'available' => (bool)($federation['available'] ?? false),
            ],
        ];
    }
}
