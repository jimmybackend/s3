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

    $fileRef = null;

    if (isset($_POST['file_id']) && trim((string)$_POST['file_id']) !== '') {
        $fileRef = (int)$_POST['file_id'];
    } elseif (isset($_POST['archivo']) && trim((string)$_POST['archivo']) !== '') {
        $fileRef = trim((string)$_POST['archivo']);
    }

    if ($fileRef === null || $fileRef === '' || $fileRef === 0) {
        throw new Exception('Falta la referencia del archivo');
    }

    $s3Manager = new S3Manager($db_connection);
    $resultado = $s3Manager->deleteFile($fileRef);

    echo json_encode([
        'ok'      => true,
        'estado'  => 'ok',
        'mensaje' => 'Archivo eliminado correctamente',
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