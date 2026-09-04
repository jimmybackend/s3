<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\UserStoragePath;

final class DrivePageService
{
    public function __construct(
        private FileListService $files,
        private UserStoragePath $paths
    ) {
    }

    public function build(array &$session, array $query, int $userId): DrivePageViewModel
    {
        $vm = new DrivePageViewModel();

        $route = $this->paths->normalizeForUser((string) ($session['ruta_actual'] ?? ''), $userId);
        $session['ruta_actual'] = $route;
        $vm->basePrefix = $route;

        $vm->tipo = strtolower(trim((string) ($query['tipo'] ?? '')));
        $vm->buscar = trim((string) ($query['buscar'] ?? ''));
        $vm->fechaInicio = trim((string) ($query['fecha_inicio'] ?? ''));
        $vm->fechaFin = trim((string) ($query['fecha_fin'] ?? ''));
        $vm->limite = max(5, min(100, (int) ($query['limite'] ?? 5)));
        $vm->pagina = max(1, (int) ($query['pagina'] ?? 1));

        try {
            $vm->extensionesUnicas = $this->files->listExtensions($userId, $route);
        } catch (\Throwable $e) {
            $vm->error = 'No se pudieron cargar los tipos de archivo: ' . $e->getMessage();
        }

        return $vm;
    }
}
