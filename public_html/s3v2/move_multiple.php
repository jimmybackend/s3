<?php

session_start();

require_once __DIR__.'/app_bootstrap.php';
require_once __DIR__.'/S3Manager.php';

try {

    if (!isset($_POST['archivos'])) {
        throw new Exception("No hay archivos");
    }

    if (!isset($_POST['ruta_destino'])) {
        throw new Exception("Falta ruta destino");
    }

    $keys = $_POST['archivos'];
    $ruta = $_POST['ruta_destino'];

    $s3 = new S3Manager($db_connection);

    $result = $s3->moveMultiple($keys,$ruta);

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