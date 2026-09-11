#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Federation\FederationGossipService;

$lockPath = sys_get_temp_dir() . '/arcadecloud-federation-sync.lock';
$lock = fopen($lockPath, 'c+');
if (!is_resource($lock)) {
    fwrite(STDERR, "No se pudo abrir lock de sincronización FederationCloud.\n");
    exit(2);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "FederationCloud sync: otra ejecución sigue activa; se omite este ciclo.\n";
    fclose($lock);
    exit(0);
}

try {
    $result = (new FederationGossipService(ApplicationKernel::app()))->syncOnce();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FederationCloud sync error: {$e->getMessage()}\n");
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

flock($lock, LOCK_UN);
fclose($lock);
