#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\FederationDirectoryService;
use Throwable;

try {
    $config = FederationConfig::fromEnvironment();
    if (!$config->enabled()) {
        fwrite(STDOUT, "SKIP: FederationCloud está desactivado.\n");
        exit(0);
    }
    if (!is_file($config->identityPath())) {
        fwrite(STDOUT, "SKIP: el nodo aún no tiene identidad FederationCloud.\n");
        exit(0);
    }

    $directory = (new FederationDirectoryService(ApplicationKernel::app()))->directory();
    $local = is_array($directory['local_node'] ?? null) ? $directory['local_node'] : [];
    $nodeId = (string)($local['node_id'] ?? '');
    $endpoint = (string)($local['federation_url'] ?? $config->federationUrl());

    if (($directory['degraded'] ?? false) === true) {
        fwrite(STDOUT, "WARN: endpoint local actualizado, pero el directorio remoto está temporalmente degradado.\n");
        fwrite(STDOUT, "NODE_ID={$nodeId}\nENDPOINT={$endpoint}\n");
        exit(0);
    }

    fwrite(STDOUT, "OK: descriptor FederationCloud firmado y publicado nuevamente.\n");
    fwrite(STDOUT, "NODE_ID={$nodeId}\nENDPOINT={$endpoint}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: no se pudo refrescar el endpoint FederationCloud: ' . $e->getMessage() . "\n");
    exit(1);
}
