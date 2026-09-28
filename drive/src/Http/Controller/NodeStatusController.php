<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use ArcadeCloud\Drive\Admin\ServerMaintenanceJobStore;
use ArcadeCloud\Drive\Admin\ServerMaintenanceService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\System\NodeCapabilityService;
use ArcadeCloud\Drive\View\FileViewHelper;
use RuntimeException;

final class NodeStatusController extends AbstractJsonController
{
    public function dispatch(): never
    {
        try {
            $userId = $this->guardAuthenticated();

            if ($this->request->method() === 'GET') {
                JsonResponse::send([
                    'ok' => true,
                    'node' => $this->snapshot(),
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
            if ($action !== 'memory-clear') {
                throw new RuntimeException('Acción de nodo no permitida.');
            }

            $result = (new ServerMaintenanceService(
                $this->app,
                new ServerMaintenanceJobStore(),
                new PrivilegedServerHelper()
            ))->queueMemoryClear(
                $userId,
                $this->request->postRawString('access_password')
            );

            JsonResponse::send([
                'ok' => true,
                'message' => ((int)($result['blocking']['active'] ?? 0) > 0)
                    ? 'La limpieza quedó en Tareas y esperará a que terminen los procesos activos.'
                    : 'La limpieza de memoria quedó programada.',
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
        $snapshot = (new NodeCapabilityService())->snapshot(dirname(__DIR__, 3));
        $diskTotal = max(0, (int)($snapshot['disk_total_bytes'] ?? 0));
        $diskFree = max(0, (int)($snapshot['disk_free_bytes'] ?? 0));
        $diskUsed = max(0, $diskTotal - $diskFree);
        $diskUsedPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0.0;

        return [
            'generated_at' => gmdate('c'),
            'hostname' => (string)($snapshot['hostname'] ?? ''),
            'role' => (string)($snapshot['role'] ?? ''),
            'instance_id' => (string)($snapshot['instance_id'] ?? ''),
            'instance_type' => (string)($snapshot['instance_type'] ?? ''),
            'availability_zone' => (string)($snapshot['availability_zone'] ?? ''),
            'vcpu' => (int)($snapshot['vcpu'] ?? 0),
            'memory_total_bytes' => (int)($snapshot['memory_total_bytes'] ?? 0),
            'memory_total' => FileViewHelper::formatBytes((int)($snapshot['memory_total_bytes'] ?? 0)),
            'memory_available_bytes' => (int)($snapshot['memory_available_bytes'] ?? 0),
            'memory_available' => FileViewHelper::formatBytes((int)($snapshot['memory_available_bytes'] ?? 0)),
            'swap_total' => FileViewHelper::formatBytes((int)($snapshot['swap_total_bytes'] ?? 0)),
            'swap_free' => FileViewHelper::formatBytes((int)($snapshot['swap_free_bytes'] ?? 0)),
            'disk_total' => FileViewHelper::formatBytes($diskTotal),
            'disk_used' => FileViewHelper::formatBytes($diskUsed),
            'disk_used_percent' => $diskUsedPercent,
            'disk_free' => FileViewHelper::formatBytes($diskFree),
            'load_average' => array_values((array)($snapshot['load_average'] ?? [0,0,0])),
            'ffmpeg_available' => (bool)($snapshot['ffmpeg_available'] ?? false),
            'ffprobe_available' => (bool)($snapshot['ffprobe_available'] ?? false),
            'docker_installed' => (bool)($snapshot['docker_installed'] ?? false),
            'gpu_present' => (bool)($snapshot['gpu_present'] ?? false),
        ];
    }
}
