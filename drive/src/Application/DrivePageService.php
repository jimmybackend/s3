<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

final class DrivePageService
{
    private \S3Manager $manager;

    public function __construct(\S3Manager $manager)
    {
        $this->manager = $manager;
    }

    public function preferencesRedirect(array &$session, array $query, string $method): ?string
    {
        if ($method !== 'GET' || !isset($query['preferencias'])) {
            return null;
        }

        $session['show_counts'] = isset($query['toggle_counts']);
        $session['show_metas'] = isset($query['toggle_metas']);
        $session['media_hidden'] = !isset($query['toggle_media']);
        $session['show_filters'] = filter_var(
            $query['toggle_filters'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        unset(
            $query['toggle_counts'],
            $query['toggle_metas'],
            $query['toggle_media'],
            $query['preferencias']
        );

        return 's3.php' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    public function build(array &$session, array $query): DrivePageViewModel
    {
        $vm = new DrivePageViewModel();
        $vm->showCounts = (bool) ($session['show_counts'] ?? false);
        $vm->showMetas = (bool) ($session['show_metas'] ?? false);
        $vm->mediaHidden = (bool) ($session['media_hidden'] ?? true);
        $vm->showFilters = (bool) ($session['show_filters'] ?? true);

        if (empty($session['ruta_actual'])) {
            $session['ruta_actual'] = \Config::RUTA_RAIZ;
        }

        $vm->basePrefix = rtrim((string) $session['ruta_actual'], '/') . '/';
        $vm->tipo = trim((string) ($query['tipo'] ?? ''));
        $vm->buscar = trim((string) ($query['buscar'] ?? ''));
        $vm->fechaInicio = trim((string) ($query['fecha_inicio'] ?? ''));
        $vm->fechaFin = trim((string) ($query['fecha_fin'] ?? ''));
        $vm->limite = max(1, (int) ($query['limite'] ?? 5));
        $vm->pagina = max(1, (int) ($query['pagina'] ?? 1));

        try {
            $archivos = $this->manager->listArchivos($vm->basePrefix, $vm->showMetas);

            $filtrados = array_filter(
                $archivos,
                static function (array $archivo) use ($vm): bool {
                    $nombre = basename((string) $archivo['Key']);
                    $fechaArchivo = $archivo['LastModified']->format('Y-m-d');
                    $ext = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));

                    if ($vm->buscar !== '' && stripos($nombre, $vm->buscar) === false) return false;
                    if ($vm->fechaInicio !== '' && $fechaArchivo < $vm->fechaInicio) return false;
                    if ($vm->fechaFin !== '' && $fechaArchivo > $vm->fechaFin) return false;
                    if ($vm->tipo !== '' && $ext !== $vm->tipo) return false;
                    return true;
                }
            );

            usort(
                $filtrados,
                static fn(array $a, array $b): int => $b['LastModified'] <=> $a['LastModified']
            );

            $vm->totalArchivos = count($filtrados);
            $vm->totalPaginas = max(1, (int) ceil($vm->totalArchivos / $vm->limite));
            $vm->pagina = min($vm->pagina, $vm->totalPaginas);
            $vm->archivosPaginados = array_slice(
                $filtrados,
                ($vm->pagina - 1) * $vm->limite,
                $vm->limite
            );

            $bytes = array_sum(array_map(
                static fn(array $archivo): int => (int) ($archivo['Size'] ?? 0),
                $vm->archivosPaginados
            ));
            $vm->pesoTotalMB = round($bytes / 1024 / 1024, 2);

            $vm->playlistAudioAll = array_values(array_filter(
                $vm->archivosPaginados,
                static function (array $archivo): bool {
                    $ext = strtolower((string) pathinfo((string) $archivo['Key'], PATHINFO_EXTENSION));
                    return in_array($ext, ['mp3', 'wav', 'ogg', 'opus', 'm4a'], true);
                }
            ));

            $vm->playlistVideoAll = array_values(array_filter(
                $vm->archivosPaginados,
                static function (array $archivo): bool {
                    $ext = strtolower((string) pathinfo((string) $archivo['Key'], PATHINFO_EXTENSION));
                    return in_array($ext, ['mp4', 'webm', 'ogg'], true);
                }
            ));

            $vm->imagenes = array_values(array_filter(
                $vm->archivosPaginados,
                static function (array $archivo): bool {
                    $ext = strtolower((string) pathinfo((string) $archivo['Key'], PATHINFO_EXTENSION));
                    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);
                }
            ));

            $vm->tieneAudio = $vm->playlistAudioAll !== [];
            $vm->tieneVideo = $vm->playlistVideoAll !== [];
            $vm->todasLasCarpetas = $this->manager->obtenerTodasLasCarpetas();

            $extensiones = [];
            foreach ($archivos as $archivo) {
                $ext = strtolower((string) pathinfo(
                    basename((string) $archivo['Key']),
                    PATHINFO_EXTENSION
                ));
                if ($ext !== '') {
                    $extensiones[$ext] = true;
                }
            }
            $vm->extensionesUnicas = array_keys($extensiones);
            sort($vm->extensionesUnicas);
        } catch (\Throwable $e) {
            $vm->error = 'Error al filtrar archivos: ' . $e->getMessage();
        }

        return $vm;
    }
}
