<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Application\FileKeyRotationService;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Core\ApplicationKernel;

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Método no permitido.');
    }
    $app = ApplicationKernel::app();
    $session = $app->session();
    $session->start();
    $userId = $session->userId();
    if ($userId <= 0) {
        throw new RuntimeException('Sesión inválida.');
    }
    $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $key = trim((string)($data['key'] ?? ''));
    if ($key === '') {
        throw new RuntimeException('Falta el nombre del archivo.');
    }
    $service = new FileKeyRotationService(
        $app->db(),
        $app->s3(),
        $app->bucket(),
        new FileRecordLocator($app->db()),
        $app->storageObjectNameCodec()
    );
    echo json_encode($service->rotate($userId, $key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['estado' => 'error', 'mensaje' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
