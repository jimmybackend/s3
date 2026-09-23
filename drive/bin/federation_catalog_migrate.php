#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;

$sqlPaths = [
    dirname(__DIR__) . '/sql/federation_global_catalog.sql',
    dirname(__DIR__) . '/sql/federation_access_shares.sql',
    dirname(__DIR__) . '/sql/federation_replicas.sql',
    dirname(__DIR__) . '/sql/federation_ingress_queue.sql',
];
$parts = [];
foreach ($sqlPaths as $sqlPath) {
    if (!is_file($sqlPath) || !is_readable($sqlPath)) {
        fwrite(STDERR, "No se encontró el esquema FederationCloud: {$sqlPath}\n");
        exit(2);
    }
    $content = (string)file_get_contents($sqlPath);
    if (trim($content) === '') {
        fwrite(STDERR, "El esquema FederationCloud está vacío: {$sqlPath}\n");
        exit(2);
    }
    $parts[] = $content;
}
$sql = implode("\n\n", $parts);

$db = ApplicationKernel::app()->db();
if (!$db->multi_query($sql)) {
    fwrite(STDERR, "Error migrando catálogo FederationCloud: {$db->error}\n");
    exit(1);
}
do {
    if ($result = $db->store_result()) $result->free();
    if (!$db->more_results()) break;
} while ($db->next_result());
if ($db->errno) {
    fwrite(STDERR, "Error completando migración FederationCloud: {$db->error}\n");
    exit(1);
}

$requiredTables = [
    'FederationOriginCounters',
    'FederationEvents',
    'FederationClocks',
    'FederatedResources',
    'FederationResourceLocations',
    'FederationPeerSyncState',
    'FederationAccessRequests',
    'FederationShares',
    'FederationShareImportJobs',
    'FederationReplicaJobs',
    'FederationReplicaObjects',
    'FederationIngressQueue',
];

$check = $db->prepare(
    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
);
if (!$check) {
    fwrite(STDERR, "No se pudo preparar la verificación del esquema FederationCloud: {$db->error}\n");
    exit(1);
}

$missing = [];
foreach ($requiredTables as $table) {
    $check->bind_param('s', $table);
    if (!$check->execute()) {
        fwrite(STDERR, "No se pudo verificar la tabla {$table}: {$check->error}\n");
        $check->close();
        exit(1);
    }
    $check->store_result();
    if ($check->num_rows !== 1) {
        $missing[] = $table;
    }
    $check->free_result();
}
$check->close();

if ($missing !== []) {
    fwrite(STDERR, 'Migración incompleta; faltan tablas FederationCloud: ' . implode(', ', $missing) . "\n");
    exit(1);
}

echo "OK: catálogo global, Aduana, solicitudes privadas, Shares y réplicas FederationCloud instalados/actualizados.\n";
