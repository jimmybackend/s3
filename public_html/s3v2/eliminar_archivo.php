<?php
/**
 * ============================================================
 * ARCHIVO: eliminar_archivo.php
 * ============================================================
 *
 * FUNCIÓN:
 * Endpoint para eliminar un archivo del sistema.
 *
 * RESPONSABILIDAD:
 * - Recibir el ID del archivo
 * - Obtener la ruta actual desde la sesión
 * - Llamar a S3Manager->deleteFile()
 *
 * IMPORTANTE:
 * Este archivo NO contiene lógica S3 ni SQL.
 * Toda la lógica vive en S3Manager.php
 *
 * FLUJO:
 *
 * Cliente (JS)
 *      ↓
 * eliminar_archivo.php
 *      ↓
 * S3Manager->deleteFile()
 *      ↓
 * 1) Eliminar archivo en S3
 * 2) Actualizar FileS3 → Found = 0
 *      ↓
 * Respuesta JSON
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
     * VALIDAR MÉTODO
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
     * VALIDAR DATOS
     * ============================================================
     */

    if (!isset($_POST['file_id'])) {
        throw new Exception('Falta el ID del archivo');
    }

    $fileId = intval($_POST['file_id']);

    /**
     * ============================================================
     * OBTENER RUTA ACTUAL
     * ============================================================
     */

    $rutaActual = $_SESSION['ruta_actual'] ?? Config::RUTA_RAIZ;

    $rutaActual = rtrim($rutaActual, '/') . '/';

    /**
     * ============================================================
     * INSTANCIAR S3Manager
     * ============================================================
     */

    $s3Manager = new S3Manager($db_connection);

    /**
     * ============================================================
     * ELIMINAR ARCHIVO
     * ============================================================
     */

    $resultado = $s3Manager->deleteFile(
        $fileId,
        $rutaActual
    );

    /**
     * ============================================================
     * RESPUESTA
     * ============================================================
     */

    echo json_encode([
        'estado' => 'ok',
        'mensaje' => 'Archivo eliminado correctamente',
        'data' => $resultado
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        'estado' => 'error',
        'mensaje' => $e->getMessage()
    ]);

}