<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/app_bootstrap.php';

$app = drive_app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión inválida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = (string) ($_POST['ruta'] ?? $_POST['rutaNueva'] ?? '');
if (trim($input) === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Ruta vacía'], JSON_UNESCAPED_UNICODE);
    exit;
}

$route = $app->userStoragePath()->normalizeForUser($input, $session->userId());
$_SESSION['ruta_actual'] = $route;

echo json_encode(['ok' => true, 'ruta' => $route], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
