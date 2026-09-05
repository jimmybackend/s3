<?php
/**
 * ============================================================
 * ARCHIVO: subir_archivo.php
 * ============================================================
 *
 * FUNCIÓN PRINCIPAL:
 * Endpoint para subir archivos al sistema.
 *
 * RESPONSABILIDAD:
 * - Recibir archivo enviado por formulario o AJAX
 * - Obtener la ruta actual desde $_SESSION['ruta_actual']
 * - Enviar el archivo a S3Manager para:
 *      1) Subir a S3
 *      2) Registrar en base de datos (tabla FileS3)
 *
 * IMPORTANTE:
 * Este archivo NO contiene lógica de S3 ni de base de datos.
 * Toda la lógica vive dentro de S3Manager.php.
 *
 * Flujo:
 *
 * Cliente
 *   ↓
 * subir_archivo.php
 *   ↓
 * S3Manager->uploadFile()
 *   ↓
 * S3 putObject
 *   ↓
 * INSERT FileS3
 *   ↓
 * respuesta JSON
 *
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

try {

    /**
     * ============================================================
     * VALIDAR MÉTODO HTTP
     * ============================================================
     */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'estado' => 'error',
            'mensaje' => 'Método no permitido'
        ]);
        exit;
    }

    /**
     * ============================================================
     * VALIDAR ARCHIVO
     * ============================================================
     */
    if (empty($_FILES['file'])) {
        throw new Exception('No se recibió archivo');
    }

    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir el archivo');
    }

    $tmpPath      = $file['tmp_name'];
    $originalName = basename($file['name']);
    $fileSize     = filesize($tmpPath);
    $mimeType     = mime_content_type($tmpPath);

    /**
     * ============================================================
     * OBTENER RUTA ACTUAL DEL USUARIO
     * ============================================================
     */
    $rutaActual = $_SESSION['ruta_actual'] ?? Config::RUTA_RAIZ;

    $rutaActual = rtrim($rutaActual, '/') . '/';

    /**
     * ============================================================
     * OBTENER ID DEL USUARIO
     * ============================================================
     */
    $userId = $_SESSION['user_id'] ?? 0;

    /**
     * ============================================================
     * INSTANCIAR S3Manager
     * ============================================================
     */
    $s3Manager = new S3Manager(db: $db_connection);

    /**
     * ============================================================
     * SUBIR ARCHIVO
     * ============================================================
     */
    $resultado = $s3Manager->uploadFile(
        $tmpPath,
        $originalName,
        $rutaActual,
        $userId,
        $mimeType,
        $fileSize
    );

    /**
     * ============================================================
     * RESPUESTA
     * ============================================================
     */
    echo json_encode([
        'estado' => 'ok',
        'mensaje' => 'Archivo subido correctamente',
        'data' => $resultado
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        'estado' => 'error',
        'mensaje' => $e->getMessage()
    ]);

}