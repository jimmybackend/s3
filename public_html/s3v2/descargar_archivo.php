<?php
/**
 * ============================================================
 * ARCHIVO: descargar_archivo.php
 * ============================================================
 * FUNCIÓN:
 * Genera una URL temporal de descarga usando la KEY S3 enviada
 * desde la interfaz.
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

try {

    if (!isset($_GET['archivo']) || trim($_GET['archivo']) === '') {
        throw new Exception("Falta la clave del archivo.");
    }

    $archivo = trim($_GET['archivo']);

    $s3Manager = new S3Manager();
    $resultado = $s3Manager->downloadFile($archivo);

    header("Location: " . $resultado['url_descarga']);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}