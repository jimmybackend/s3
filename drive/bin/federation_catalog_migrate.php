#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;

$sqlPaths = [
    dirname(__DIR__) . '/sql/federation_global_catalog.sql',
    dirname(__DIR__) . '/sql/federation_access_shares.sql',
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

echo "OK: catálogo global, solicitudes privadas y Shares FederationCloud instalados/actualizados.\n";
