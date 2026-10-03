<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/src/Admin/ProductionPreflightService.php');
$script = (string)file_get_contents($root . '/bin/production_preflight.php');
$doc = (string)file_get_contents($root . '/docs/MONDAY_PRODUCTION_VALIDATION.md');

$ok = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
};

$ok(str_contains($service, 'ProductionPreflightService'), 'preflight uses a dedicated service');
$ok(str_contains($service, 'FederationConfig::fromEnvironment()'), 'preflight validates FederationCloud config');
$ok(str_contains($service, 'NodeIdentityService'), 'preflight validates node identity');
$ok(str_contains($service, 'information_schema.TABLES'), 'preflight checks canonical federation schema');
$ok(str_contains($service, 'FederationResourceDeliveries'), 'preflight requires delivery history schema');
$ok(str_contains($service, 'FederationResourceDeliverySources'), 'preflight requires delivery source schema');
$ok(str_contains($service, 'NodeCapabilityService'), 'preflight checks local capacity without mutations');
$ok(!str_contains($service . $script, 'UPDATE '), 'preflight does not update database rows');
$ok(!str_contains($service . $script, 'DELETE '), 'preflight does not delete database rows');
$ok(!str_contains($service . $script, 'INSERT '), 'preflight does not insert database rows');
$ok(!str_contains($service . $script, 'shell_exec'), 'preflight does not invoke arbitrary shell commands');
$ok(!str_contains($service . $script, 'system('), 'preflight does not invoke system shell');
$ok(str_contains($script, 'JSON_PRETTY_PRINT'), 'preflight emits readable JSON');
$ok(str_contains($doc, 'No restaurar encima de la base productiva'), 'Monday checklist explicitly protects production DB');
$ok(str_contains($doc, 'grupos de 10 acciones'), 'Monday checklist covers mobile paging');
$ok(str_contains($doc, 'multisource'), 'Monday checklist covers real multisource federation');

fwrite(STDOUT, "Production preflight contract passed.\n");
