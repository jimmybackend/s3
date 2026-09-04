<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

final class DrivePageViewModel
{
    public bool $showCounts = false;
    public bool $showMetas = false;
    public bool $mediaHidden = true;
    public bool $showFilters = true;
    public string $basePrefix = '';
    public string $tipo = '';
    public string $buscar = '';
    public string $fechaInicio = '';
    public string $fechaFin = '';
    public int $limite = 5;
    public int $pagina = 1;
    public int $totalPaginas = 1;
    public int $totalArchivos = 0;
    public float $pesoTotalMB = 0.0;
    public string $error = '';
    public array $extensionesUnicas = [];
    public array $playlistAudioAll = [];
    public array $playlistVideoAll = [];
    public bool $tieneAudio = false;
    public bool $tieneVideo = false;
    public array $archivosPaginados = [];
    public array $todasLasCarpetas = [];
    public array $imagenes = [];
    public array $folder = [];
    public array $ruta = [];
}
