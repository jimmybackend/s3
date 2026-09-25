#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Federation\FederationSchemaMigrationService;
use Throwable;

try {
    $result = (new FederationSchemaMigrationService(ApplicationKernel::app()->db()))->reconcile();
    fwrite(STDOUT, 'OK: ' . (string)($result['message'] ?? 'Esquema FederationCloud reconciliado.') . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
