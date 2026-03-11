<?php

session_start();

require_once __DIR__.'/app_bootstrap.php';
require_once __DIR__.'/S3Manager.php';

try {

    $keys = [];

    if (isset($_POST['archivos']) && is_array($_POST['archivos'])) {
        $keys = $_POST['archivos'];
    } elseif (isset($_POST['archivos_json'])) {
        $tmp = json_decode((string)$_POST['archivos_json'], true);
        if (is_array($tmp)) {
            $keys = $tmp;
        }
    }

    if (empty($keys)) {
        throw new Exception("No hay archivos");
    }

    $s3 = new S3Manager($db_connection);

    $result = $s3->deleteMultiple($keys);

    echo json_encode([
        'estado' => 'ok',
        'ok'     => true,
        'data'   => $result
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
