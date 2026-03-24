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

    $zipFile = $s3->downloadMultiple($keys);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="archivos.zip"');
    header('Content-Length: ' . filesize($zipFile));

    readfile($zipFile);

    unlink($zipFile);

} catch (Exception $e) {

    http_response_code(500);
    echo $e->getMessage();

}