<?php
require_once '../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';


$bucket = Config::BUCKET;
$prefix = $_GET['ruta'] ?? 'Data/';
$prefix = urldecode(rtrim($prefix, '/')) . '/';

try {
    $s3 = Config::getS3();
    $result = $s3->listObjectsV2([
        'Bucket'    => $bucket,
        'Prefix'    => $prefix,
        'Delimiter' => '/',
    ]);

    foreach ($result['Contents'] ?? [] as $obj) {
        if (substr($obj['Key'], -1) === '/' || $obj['Key'] === $prefix) continue;

        $nombre = basename($obj['Key']);
        $fecha = date('Y-m-d H:i:s', strtotime($obj['LastModified']));
        $pesoMB = round($obj['Size'] / 1024 / 1024, 2);

        echo "<li class='list-group-item d-flex justify-content-between align-items-center'>
                <div>
                    <strong>" . htmlspecialchars($nombre) . "</strong><br>
                    <small class='text-muted'>$fecha | $pesoMB MB</small>
                </div>
              </li>";
    }

} catch (Exception $e) {
    echo "<li class='list-group-item text-danger'>Error al listar archivos: {$e->getMessage()}</li>";
}
