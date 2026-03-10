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

    $rutaBase = trim((string)($_POST['ruta'] ?? ''));
    $nueva    = trim((string)($_POST['nueva'] ?? ''));

    if ($rutaBase === '') {
        throw new Exception('Falta la ruta base.');
    }

    if ($nueva === '') {
        throw new Exception('Debes indicar el nombre de la carpeta.');
    }

    $manager = new S3Manager();
    $manager->crearCarpeta($rutaBase, $nueva);

    echo json_encode([
        'ok'      => true,
        'message' => 'Carpeta creada correctamente.',
        'ruta'    => $rutaBase,
        'nombre'  => $nueva
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}