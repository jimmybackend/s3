#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Federation\FederationDropService;

try {
    $limit = isset($argv[1]) && preg_match('/\A\d+\z/', (string)$argv[1]) ? (int)$argv[1] : 100;
    $result = (new FederationDropService(ApplicationKernel::app()))->cleanup($limit);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(((int)($result['errors'] ?? 0)) > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FederationDrop cleanup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
