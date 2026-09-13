#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Federation\FederationReplicaPresenceService;

try {
    $result = (new FederationReplicaPresenceService(ApplicationKernel::app()))->announce();
    fwrite(STDOUT, json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: no se pudo anunciar la presencia de la réplica FederationCloud: ' . $e->getMessage() . "\n");
    exit(1);
}
