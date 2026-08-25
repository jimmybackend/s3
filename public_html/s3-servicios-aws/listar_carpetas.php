<?php
require_once '../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $manager = new S3Manager();

    $carpetas = $manager->listarCarpetasDesdeDb();

    echo json_encode([
        'ok' => true,
        'carpetas' => $carpetas
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}