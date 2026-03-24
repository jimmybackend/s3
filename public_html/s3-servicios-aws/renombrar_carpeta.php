<?php
header('Content-Type: application/json; charset=UTF-8');
session_start();

require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Método no permitido.');
    }

    $ruta  = trim((string)($_POST['ruta'] ?? ''));
    $nuevo = trim((string)($_POST['nuevo'] ?? ''));

    if ($ruta === '') {
        throw new Exception('Falta la ruta de la carpeta.');
    }

    if ($nuevo === '') {
        throw new Exception('Debes indicar el nuevo nombre.');
    }

    $manager = new S3Manager();
    $manager->renombrarCarpeta($ruta, $nuevo);

    echo json_encode([
        'ok'      => true,
        'message' => 'Carpeta renombrada correctamente.',
        'ruta'    => $ruta,
        'nuevo'   => $nuevo
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}