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

    public function preferencesRedirect(array &$session, array $query, string $method): ?string
    {
        if ($method !== 'GET' || !isset($query['preferencias'])) {
            return null;
        }

        $session['show_counts'] = isset($query['toggle_counts']);
        $session['show_metas'] = isset($query['toggle_metas']);
        $session['media_hidden'] = !isset($query['toggle_media']);
        $session['show_filters'] = filter_var($query['toggle_filters'] ?? false, FILTER_VALIDATE_BOOLEAN);

        unset($query['toggle_counts'], $query['toggle_metas'], $query['toggle_media'], $query['preferencias']);
        return 's3.php' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    public function build(array &$session, array $query, int $userId): DrivePageViewModel
    {
        $vm = new DrivePageViewModel();
        $vm->showCounts = (bool) ($session['show_counts'] ?? false);
        $vm->showMetas = (bool) ($session['show_metas'] ?? false);
        $vm->mediaHidden = (bool) ($session['media_hidden'] ?? true);
        $vm->showFilters = (bool) ($session['show_filters'] ?? true);

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
