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
    public string $error = '';
    public array $extensionesUnicas = [];
}
