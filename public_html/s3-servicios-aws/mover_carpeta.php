<?php
header('Content-Type: application/json; charset=UTF-8');
session_start();

require_once '../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Método no permitido.');
    }

    $origen  = trim((string)($_POST['origen'] ?? ''));
    $destino = trim((string)($_POST['destino'] ?? ''));

    if ($origen === '') {
        throw new Exception('Falta la carpeta origen.');
    }

    if ($destino === '') {
        throw new Exception('Falta la carpeta destino.');
    }

    $manager = new S3Manager();
    $manager->moverCarpeta($origen, $destino);

    echo json_encode([
        'ok'      => true,
        'message' => 'Carpeta movida correctamente.',
        'origen'  => $origen,
        'destino' => $destino
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}