<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Aws\ComprehendFileService;

try {
    $app = drive_app();
    $session = $app->session();
    $session->start();
    $session->requireAuthenticated('index.php');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('Método no permitido.');
    }

    $key = trim((string)($_POST['key'] ?? $_POST['archivo'] ?? ''));
    if ($key === '') {
        throw new RuntimeException('Falta el archivo a analizar.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $service = new ComprehendFileService(
        $app->db(),
        $app->s3(),
        Config::getComprehend(),
        $app->bucket()
    );

    echo json_encode([
        'ok' => true,
        'analysis' => $service->analyze($session->userId(), $key),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
