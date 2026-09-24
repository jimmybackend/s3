#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;

$schemaPath = dirname(__DIR__, 2) . '/adbbmis1_Cloud.sql';
$startMarker = '-- ARCADECLOUD:FEDERATION_SCHEMA:BEGIN';
$endMarker = '-- ARCADECLOUD:FEDERATION_SCHEMA:END';

if (!is_file($schemaPath) || !is_readable($schemaPath)) {
    fwrite(STDERR, "No se encontró el SQL canónico ArcadeCloud: {$schemaPath}\n");
    exit(2);
}

$content = (string)file_get_contents($schemaPath);
$start = strpos($content, $startMarker);
$end = $start === false ? false : strpos($content, $endMarker, $start + strlen($startMarker));
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "El SQL canónico no contiene la sección FederationCloud marcada.\n");
    exit(2);
}

$sqlStart = $start + strlen($startMarker);
$sql = trim(substr($content, $sqlStart, $end - $sqlStart));
if ($sql === '') {
    fwrite(STDERR, "La sección FederationCloud del SQL canónico está vacía.\n");
    exit(2);
}

$db = ApplicationKernel::app()->db();
if (!$db->multi_query($sql)) {
    fwrite(STDERR, "Error migrando catálogo FederationCloud: {$db->error}\n");
    exit(1);
}
do {
    if ($result = $db->store_result()) {
        $result->free();
    }
    if (!$db->more_results()) {
        break;
    }
} while ($db->next_result());

if ($db->errno) {
    fwrite(STDERR, "Error completando migración FederationCloud: {$db->error}\n");
    exit(1);
}

$requiredTables = [
    'FederationNodes',
    'FederationNodeAuthorizations',
    'FederationOriginCounters',
    'FederationEvents',
    'FederationClocks',
    'FederatedResources',
    'FederationResourceLocations',
    'FederationPeerSyncState',
    'FederationAccessRequests',
    'FederationShares',
    'FederationShareImportJobs',
    'FederationPublicImportJobs',
    'FederationReplicaJobs',
    'FederationReplicaObjects',
    'FederationIngressQueue',
    'FederationDrops',
    'FederationDropPaymentEvents',
    'FederationDropIngressObjects',
    'FederationCommercialProviders',
    'FederationDropPlacements',
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

echo "OK: esquema FederationCloud completo instalado/actualizado desde adbbmis1_Cloud.sql.\n";
