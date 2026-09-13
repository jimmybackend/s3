#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Federation\FederationCatalogService;
use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\FederationDirectoryService;

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

    $app = ApplicationKernel::app();

    // Al arrancar o cambiar endpoint, el nodo vuelve a presentarse al directorio.
    // En un seed remoto la presentación entra a Aduana y no requiere superadmin.
    $directory = (new FederationDirectoryService($app))->directory();

    // Deja inmediatamente un node.upsert local para que el siguiente ciclo gossip
    // replique disponibilidad/endpoint a toda la federación.
    $presenceEvent = (new FederationCatalogService($app))->announceNodeIfChanged();

    $local = is_array($directory['local_node'] ?? null) ? $directory['local_node'] : [];
    $nodeId = (string)($local['node_id'] ?? '');
    $endpoint = (string)($local['federation_url'] ?? $config->federationUrl());
    $registration = is_array($directory['registration'] ?? null) ? $directory['registration'] : null;

    if (($directory['degraded'] ?? false) === true) {
        fwrite(STDOUT, "WARN: endpoint local actualizado; el anuncio firmado quedó local y el directorio remoto se reintentará por gossip.\n");
        fwrite(STDOUT, "NODE_ID={$nodeId}\nENDPOINT={$endpoint}\n");
        if (is_array($presenceEvent) && isset($presenceEvent['event_id'])) {
            fwrite(STDOUT, 'PRESENCE_EVENT_ID=' . (string)$presenceEvent['event_id'] . "\n");
        }
        exit(0);
    }

    fwrite(STDOUT, "OK: nodo FederationCloud presentado y disponible para replicación.\n");
    fwrite(STDOUT, "NODE_ID={$nodeId}\nENDPOINT={$endpoint}\n");
    if (is_array($registration)) {
        fwrite(STDOUT, 'REGISTRATION=' . (string)($registration['queue_status'] ?? 'accepted') . "\n");
    }
    if (is_array($presenceEvent) && isset($presenceEvent['event_id'])) {
        fwrite(STDOUT, 'PRESENCE_EVENT_ID=' . (string)$presenceEvent['event_id'] . "\n");
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: no se pudo refrescar el endpoint FederationCloud: ' . $e->getMessage() . "\n");
    exit(1);
}
