<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Office\OfficeDocumentStorageService;

if ((string)($_SERVER['ARCADECLOUD_OFFICE_DOCUMENT_GATE'] ?? '') !== '1') {
    http_response_code(404);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    $respond(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$action = strtolower(trim((string)($_SERVER['HTTP_X_ARCADECLOUD_OFFICE_DOCUMENT_ACTION'] ?? '')));
if (!in_array($action, ['prepare', 'sync', 'close'], true)) {
    $respond(['ok' => false, 'error' => 'Acción documental no permitida.'], 400);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 8192) {
    $respond(['ok' => false, 'error' => 'Payload documental inválido.'], 400);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    $respond(['ok' => false, 'error' => 'JSON documental inválido.'], 400);
}

$sessionId = strtolower(trim((string)($data['session_id'] ?? '')));
$controlToken = strtolower(trim((string)($data['control_token'] ?? '')));
if (
    !preg_match('/^[a-f0-9]{32}$/', $sessionId)
    || !preg_match('/^[a-f0-9]{64}$/', $controlToken)
) {
    $respond(['ok' => false, 'error' => 'Credenciales documentales inválidas.'], 400);
}

try {
    $service = new OfficeDocumentStorageService(ApplicationKernel::app());
    $result = match ($action) {
        'prepare' => $service->prepare($sessionId, $controlToken),
        'close' => $service->sync($sessionId, $controlToken, true),
        default => $service->sync($sessionId, $controlToken, false),
    };
    $respond(array_merge(['ok' => true], $result));
} catch (Throwable $e) {
    error_log('[Office document gateway] ' . $e->getMessage());
    $respond(['ok' => false, 'error' => $e->getMessage()], 409);
}
