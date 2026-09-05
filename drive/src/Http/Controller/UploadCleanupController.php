<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\Upload\UploadCleanupService;

final class UploadCleanupController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    /**
     * Vista web deliberadamente no destructiva.
     * La eliminación real sólo se permite desde drive/bin/upload_cleanup.php.
     */
    public function preview(): never
    {
        $session = $this->app->session();
        $session->start();

        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::send(['ok' => false, 'error' => 'Sesión inválida.'], 401);
        }

        $role = trim((string)$session->get('role', ''));
        if (!in_array($role, ['Administración', 'Soporte'], true)) {
            JsonResponse::send(['ok' => false, 'error' => 'No autorizado.'], 403);
        }

        if ($this->request->method() !== 'GET') {
            JsonResponse::send([
                'ok' => false,
                'error' => 'La vista web de limpieza es sólo de consulta. Usa el comando CLI para borrar.',
            ], 405);
        }

        $days = (int)$this->request->queryString('days', '30');
        $days = max(1, min(3650, $days));

        $session->closeWrite();

        try {
            $report = $this->service()->run($days, false);
            JsonResponse::send([
                'ok' => true,
                'mode' => 'dry-run',
                'message' => 'No se eliminó nada. Para ejecutar la limpieza usa drive/bin/upload_cleanup.php --execute.',
                'report' => $report,
            ]);
        } catch (\Throwable $e) {
            JsonResponse::send([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function service(): UploadCleanupService
    {
        return new UploadCleanupService(
            $this->app->db(),
            $this->app->s3(),
            $this->app->bucket(),
            $this->app->userStoragePath(),
            sys_get_temp_dir() . '/arcadecloud-public-upload-state'
        );
    }
}
