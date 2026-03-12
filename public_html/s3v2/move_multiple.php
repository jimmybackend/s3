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

    $keys = [];

    if (isset($_POST['archivos']) && is_array($_POST['archivos'])) {
        $keys = $_POST['archivos'];
    } elseif (isset($_POST['archivos_json'])) {
        $tmp = json_decode((string)$_POST['archivos_json'], true);
        if (is_array($tmp)) {
            $keys = $tmp;
        }
    }

    $keys = array_values(array_filter(array_map(static function ($k) {
        return trim((string)$k);
    }, $keys)));

    if (empty($keys)) {
        throw new Exception('No hay archivos');
    }

    $ruta = trim((string)($_POST['ruta_destino'] ?? $_POST['nueva_ruta'] ?? ''));

    if ($ruta === '') {
        throw new Exception('Falta ruta destino');
    }

    $ruta = rtrim(str_replace('\\', '/', $ruta), '/') . '/';

    $s3 = new S3Manager($db_connection);
    $result = $s3->moveMultiple($keys, $ruta);

    echo json_encode([
        'ok'      => true,
        'estado'  => 'ok',
        'mensaje' => 'Archivos movidos correctamente',
        'data'    => $result
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