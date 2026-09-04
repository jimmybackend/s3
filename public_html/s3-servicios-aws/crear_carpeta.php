<?php
header('Content-Type: application/json; charset=UTF-8');
session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Método no permitido.');
    }

    $nueva = trim((string)($_POST['nueva'] ?? ''));
    if ($nueva === '') {
        throw new Exception('Debes indicar el nombre de la carpeta.');
    }

    $ruta = $_SESSION['ruta_actual'] ?? 'Data/';
    $ruta = trim((string)$ruta);

    if ($ruta === '') {
        $ruta = 'Data/';
    }

    $manager = new S3Manager();
    $manager->crearCarpeta($ruta, $nueva);

    $_SESSION['ruta_actual'] = $ruta;

    echo json_encode([
        'ok' => true,
        'message' => 'Carpeta creada correctamente.',
        'ruta_actual' => $ruta,
        'nombre' => $nueva
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}