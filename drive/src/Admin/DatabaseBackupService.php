<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Core\DriveApplication;
use RuntimeException;

final class DatabaseBackupService
{
    public function __construct(private DriveApplication $app)
    {
    }

    public function createForSuperAdmin(int $userId): array
    {
        if ($userId <= 0 || !$this->app->session()->isSuperAdmin()) {
            throw new RuntimeException('Acceso reservado al superadministrador.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'arcadecloud-db-');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del respaldo.');
        }

        $filename = 'ArcadeCloud_DB_' . date('Y-m-d_His') . '.sql';

        try {
            $verification = (new DatabaseSqlDumpWriter())->dump($this->app->db(), $tmp);
            if (($verification['verified_complete'] ?? false) !== true) {
                throw new RuntimeException('El respaldo no alcanzó el estado de verificación completa.');
            }

            $size = (int)($verification['size'] ?? 0);
            if ($size <= 0) {
                throw new RuntimeException('El respaldo generado está vacío.');
            }

            $root = $this->app->userStoragePath()->rootForUser($userId);
            $route = rtrim($root, '/') . '/Backup/';
            $this->app->uploadCatalogRepository()->ensureFolder(
                $userId,
                $route,
                'Backup',
                $root
            );

            $uploaded = $this->app->singleUploadService()->upload(
                $tmp,
                $filename,
                $route,
                $userId,
                'application/sql',
                $size,
                'server-generated',
                'ArcadeCloud verified database backup'
            );

            return [
                'filename' => $filename,
                'route' => $route,
                'size' => $size,
                'sha256' => (string)($verification['sha256'] ?? ''),
                'database' => (string)($verification['database'] ?? ''),
                'inventory' => (array)($verification['inventory'] ?? []),
                'verified_complete' => true,
                'file_id' => (int)($uploaded['id'] ?? 0),
            ];
        } finally {
            @unlink($tmp);
        }
    }
}
