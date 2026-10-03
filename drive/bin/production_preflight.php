<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Admin\ProductionPreflightService;
use ArcadeCloud\Drive\Core\ApplicationKernel;

$projectRoot = realpath(dirname(__DIR__, 2));
if ($projectRoot === false) {
    fwrite(STDERR, "No se pudo resolver la raíz del proyecto.\n");
    exit(2);
}

$result = (new ProductionPreflightService(ApplicationKernel::app()->db(), $projectRoot))->run();

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($json)) {
    fwrite(STDERR, "No se pudo serializar el diagnóstico.\n");
    exit(2);
}

fwrite(STDOUT, $json . "\n");
exit(($result['ok'] ?? false) ? 0 : 1);
