<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'ok'      => false,
            'estado'  => 'error',
            'mensaje' => 'Método no permitido',
            'error'   => 'Método no permitido'
        ]);
        exit;
    }

    $key = trim((string)($_POST['key'] ?? ''));
    $nombreNuevo = trim((string)($_POST['nombre_nuevo'] ?? ''));

    if ($key === '') {
        throw new Exception('Falta la clave del archivo.');
    }

    if ($nombreNuevo === '') {
        throw new Exception('Falta el nuevo nombre del archivo.');
    }

    $s3Manager = new S3Manager($db_connection);
    $resultado = $s3Manager->renameFile($key, $nombreNuevo);

    echo json_encode([
        'ok'      => true,
        'estado'  => 'ok',
        'mensaje' => 'Archivo renombrado correctamente',
        'data'    => $resultado
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok'      => false,
        'estado'  => 'error',
        'mensaje' => $e->getMessage(),
        'error'   => $e->getMessage()
    ]);
}