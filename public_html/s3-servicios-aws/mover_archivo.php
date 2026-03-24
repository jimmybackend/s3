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

    $s3Manager = new S3Manager($db_connection);

    $nuevaRuta = trim((string)($_POST['nueva_ruta'] ?? $_POST['ruta_destino'] ?? ''));

    if ($nuevaRuta === '') {
        throw new Exception('Falta la ruta destino');
    }

    $nuevaRuta = rtrim(str_replace('\\', '/', $nuevaRuta), '/') . '/';

    // -------------------------------------------------
    // 1) Compatibilidad: mover un archivo por ID
    // -------------------------------------------------
    if (isset($_POST['file_id']) && trim((string)$_POST['file_id']) !== '') {
        $fileId = (int)$_POST['file_id'];

        if ($fileId <= 0) {
            throw new Exception('ID de archivo inválido');
        }

        $resultado = $s3Manager->moveFile($fileId, $nuevaRuta);

        echo json_encode([
            'ok'      => true,
            'estado'  => 'ok',
            'mensaje' => 'Archivo movido correctamente',
            'data'    => $resultado
        ]);
        exit;
    }

    // -------------------------------------------------
    // 2) Compatibilidad: mover uno o varios archivos por key
    // -------------------------------------------------
    $keys = [];

    if (isset($_POST['archivos']) && is_array($_POST['archivos'])) {
        $keys = $_POST['archivos'];
    } elseif (isset($_POST['archivos_json'])) {
        $raw = $_POST['archivos_json'];

        if (is_array($raw)) {
            $keys = $raw;
        } else {
            $raw = trim((string)$raw);

            if ($raw !== '') {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    $keys = $decoded;
                } elseif (is_string($decoded) && trim($decoded) !== '') {
                    $keys = [trim($decoded)];
                } else {
                    $keys = [$raw];
                }
            }
        }
    } elseif (isset($_POST['archivo']) && trim((string)$_POST['archivo']) !== '') {
        $keys = [trim((string)$_POST['archivo'])];
    }

    $keys = array_values(array_filter(array_map(static function ($item) {
        return trim((string)$item);
    }, $keys)));

    if (empty($keys)) {
        throw new Exception('No hay archivos seleccionados');
    }

    if (!method_exists($s3Manager, 'moveMultiple')) {
        throw new Exception('El método moveMultiple() no existe en S3Manager');
    }

    $resultado = $s3Manager->moveMultiple($keys, $nuevaRuta);

    echo json_encode([
        'ok'      => true,
        'estado'  => 'ok',
        'mensaje' => 'Archivo(s) movido(s) correctamente',
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