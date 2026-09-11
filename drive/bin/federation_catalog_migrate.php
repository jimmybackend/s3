#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;

$sqlPath = dirname(__DIR__) . '/sql/federation_global_catalog.sql';
if (!is_file($sqlPath) || !is_readable($sqlPath)) {
    fwrite(STDERR, "No se encontró el esquema FederationCloud: {$sqlPath}\n");
    exit(2);
}
$sql = (string)file_get_contents($sqlPath);
if (trim($sql) === '') {
    fwrite(STDERR, "El esquema FederationCloud está vacío.\n");
    exit(2);
}

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

echo "OK: esquema de catálogo global FederationCloud instalado/actualizado.\n";
