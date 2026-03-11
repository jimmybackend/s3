<?php
/**
 * mover_archivo.php
 * Soporta mover uno (file_id) o varios (archivos_json/archivos) usando S3Manager.
 */

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
            'estado'  => 'error',
            'ok'      => false,
            'mensaje' => 'Método no permitido',
            'error'   => 'Método no permitido'
        ]);
        exit;
    }

    $nuevaRuta = trim((string)($_POST['nueva_ruta'] ?? $_POST['ruta_destino'] ?? ''));
    if ($nuevaRuta === '') {
        throw new Exception('Falta la ruta destino');
    }
    $nuevaRuta = rtrim($nuevaRuta, '/') . '/';

    $s3Manager = new S3Manager($db_connection);

    $resultado = null;

    // Flujo múltiple (desde modal de s3.php / archivos.js)
    $keys = [];
    $hadBatchPayload = isset($_POST['ruta_actual']);

    if (isset($_POST['archivos'])) {
        $hadBatchPayload = true;
        if (is_array($_POST['archivos'])) {
            $keys = $_POST['archivos'];
        } elseif (trim((string)$_POST['archivos']) !== '') {
            $keys = [$_POST['archivos']];
        }
    } elseif (isset($_POST['archivos_json'])) {
        $hadBatchPayload = true;
        $raw = $_POST['archivos_json'];

        if (is_array($raw)) {
            $keys = $raw;
        } else {
            $rawStr = trim((string)$raw);
            if ($rawStr !== '') {
                $tmp = json_decode($rawStr, true);
                if (is_array($tmp)) {
                    $keys = $tmp;
                } elseif (is_string($tmp) && trim($tmp) !== '') {
                    $keys = [$tmp];
                } else {
                    // fallback: venía una sola key plana o CSV
                    if (strpos($rawStr, ',') !== false) {
                        $keys = array_map('trim', explode(',', $rawStr));
                    } else {
                        $keys = [$rawStr];
                    }
                }
            }
        }
    } elseif (isset($_POST['archivo']) && trim((string)$_POST['archivo']) !== '') {
        // Compatibilidad: key única enviada como "archivo"
        $hadBatchPayload = true;
        $keys = [$_POST['archivo']];
    }

    if (!empty($keys)) {
        $keys = array_values(array_filter(array_map(function ($k) {
            return trim(urldecode((string)$k));
        }, $keys)));
    }

    if (!empty($keys)) {
        $resultado = $s3Manager->moveMultiple($keys, $nuevaRuta);
        echo json_encode([
            'estado'  => 'ok',
            'ok'      => true,
            'mensaje' => 'Archivos movidos correctamente',
            'data'    => $resultado
        ]);
        exit;
    }

    if ($hadBatchPayload) {
        throw new Exception('No hay archivos seleccionados');
    }

    // Flujo individual
    if (!isset($_POST['file_id']) || trim((string)$_POST['file_id']) === '') {
        throw new Exception('Falta el ID del archivo');
    }

    $fileId = (int)$_POST['file_id'];
    if ($fileId <= 0) {
        throw new Exception('ID de archivo inválido');
    }

    $resultado = $s3Manager->moveFile($fileId, $nuevaRuta);

    echo json_encode([
        'estado'  => 'ok',
        'ok'      => true,
        'mensaje' => 'Archivo movido correctamente',
        'data'    => $resultado
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'estado'  => 'error',
        'ok'      => false,
        'mensaje' => $e->getMessage(),
        'error'   => $e->getMessage()
    ]);
}
