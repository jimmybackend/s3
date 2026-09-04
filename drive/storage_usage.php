<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Http\JsonResponse;

$app = drive_app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    JsonResponse::error('Sin sesión', 401);
}

$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$usage = $app->storageUsageService()->getUsage($session->userId(), $force);

JsonResponse::ok([
    'bytes' => $usage['bytes'],
    'formatted' => $usage['formatted'],
    'cached_at' => $usage['cached_at'],
]);
