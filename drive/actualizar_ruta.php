<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$app = drive_app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sin sesión'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ruta = trim((string) ($_POST['ruta'] ?? $_POST['rutaNueva'] ?? ''));
if ($ruta === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Ruta vacía'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ruta = $app->userStoragePath()->normalizeForUser($ruta, $session->userId());
$_SESSION['ruta_actual'] = $ruta;

echo json_encode(['ok' => true, 'ruta' => $ruta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
