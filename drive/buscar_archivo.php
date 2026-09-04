<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Application\FileSearchService;

try {
    $app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
    $session = $app->session();
    $session->start();
    $session->requireAuthenticated('index.php');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'estado' => 'error',
            'mensaje' => 'Método no permitido.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $term = trim((string)($_POST['termino'] ?? ''));
    if ($term === '') {
        http_response_code(400);
        echo json_encode([
            'estado' => 'error',
            'mensaje' => 'Escribe un nombre o patrón para buscar.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $service = new FileSearchService($app->db());
    $results = $service->search($session->userId(), $term, 200);

    echo json_encode([
        'estado' => 'ok',
        'termino' => $term,
        'total' => count($results),
        'resultados' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'estado' => 'error',
        'mensaje' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
