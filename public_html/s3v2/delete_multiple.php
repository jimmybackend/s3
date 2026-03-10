<?php

session_start();

require_once __DIR__.'/app_bootstrap.php';
require_once __DIR__.'/S3Manager.php';

try {

    if (!isset($_POST['archivos'])) {
        throw new Exception("No hay archivos");
    }

    $keys = $_POST['archivos'];

    $s3 = new S3Manager($db_connection);

    $result = $s3->deleteMultiple($keys);

    echo json_encode([
        'estado'=>'ok',
        'data'=>$result
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        'estado'=>'error',
        'mensaje'=>$e->getMessage()
    ]);

}