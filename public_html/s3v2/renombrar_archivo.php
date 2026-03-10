<?php
/**
 * ============================================================
 * ARCHIVO: renombrar_archivo.php
 * ============================================================
 * FUNCIÓN:
 * Renombra un archivo usando la KEY S3 enviada desde la interfaz.
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    if (!isset($_POST['key']) || trim($_POST['key']) === '') {
        throw new Exception('Falta la clave del archivo.');
    }

    if (!isset($_POST['nombre_nuevo']) || trim($_POST['nombre_nuevo']) === '') {
        throw new Exception('Falta el nuevo nombre del archivo.');
    }

    $key = trim($_POST['key']);
    $nombreNuevo = trim($_POST['nombre_nuevo']);

    $s3Manager = new S3Manager();
    $resultado = $s3Manager->renameFile($key, $nombreNuevo);

    echo json_encode([
        'estado'  => 'ok',
        'mensaje' => 'Archivo renombrado correctamente',
        'data'    => $resultado
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'estado'  => 'error',
        'mensaje' => $e->getMessage()
    ]);
}