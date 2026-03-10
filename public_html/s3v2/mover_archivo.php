<?php
/**
 * ============================================================
 * ARCHIVO: mover_archivo.php
 * ============================================================
 *
 * FUNCIÓN:
 * Endpoint para mover un archivo entre carpetas.
 *
 * RESPONSABILIDAD:
 * - Recibir el ID del archivo
 * - Recibir la nueva ruta destino
 * - Llamar a S3Manager->moveFile()
 *
 * IMPORTANTE:
 * Este archivo NO contiene lógica S3 ni SQL.
 * Toda la lógica vive dentro de S3Manager.php
 *
 * FLUJO:
 *
 * Cliente (JS)
 *      ↓
 * mover_archivo.php
 *      ↓
 * S3Manager->moveFile()
 *      ↓
 * 1) Copiar archivo en S3 a la nueva ruta
 * 2) Eliminar archivo original en S3
 * 3) Actualizar Ruta en FileS3
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
     * VALIDAR DATOS
     * ============================================================
     */

    if (!isset($_POST['file_id'])) {
        throw new Exception('Falta el ID del archivo');
    }

    if (!isset($_POST['nueva_ruta'])) {
        throw new Exception('Falta la ruta destino');
    }

    $fileId = intval($_POST['file_id']);
    $nuevaRuta = trim($_POST['nueva_ruta']);

    if ($nuevaRuta === '') {
        throw new Exception('Ruta destino inválida');
    }

    /**
     * ============================================================
     * NORMALIZAR RUTA DESTINO
     * ============================================================
     */

    $nuevaRuta = rtrim($nuevaRuta, '/') . '/';

    /**
     * ============================================================
     * CREAR INSTANCIA S3Manager
     * ============================================================
     */

    $s3Manager = new S3Manager($db_connection);

    /**
     * ============================================================
     * EJECUTAR MOVIMIENTO
     * ============================================================
     */

    $resultado = $s3Manager->moveFile(
        $fileId,
        $nuevaRuta
    );

    /**
     * ============================================================
     * RESPUESTA JSON
     * ============================================================
     */

    echo json_encode([
        'estado' => 'ok',
        'mensaje' => 'Archivo movido correctamente',
        'data' => $resultado
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        'estado' => 'error',
        'mensaje' => $e->getMessage()
    ]);

}