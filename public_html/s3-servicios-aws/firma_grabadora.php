<?php
// firma_grabadora.php
// Devuelve una URL presignada para subir DIRECTO a S3 con PUT al folder de $_SESSION['ruta_actual']

header('Content-Type: application/json; charset=UTF-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\Exception\AwsException;

try {
    // Acepta JSON o form
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;

    $filename = isset($data['filename']) ? trim((string)$data['filename']) : '';
    $mime     = isset($data['mime']) ? (string)$data['mime'] : '';

    if ($filename === '' || strpos($mime, 'audio/') !== 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'Parámetros inválidos']);
        exit;
    }

    // Sanea nombre y arma key con la ruta actual
    $filename = preg_replace('/[\\\\\\/:*?"<>|]+/', ' ', $filename);
    $filename = preg_replace('/\\s+/', ' ', $filename);

    $ruta = $_SESSION['ruta_actual'] ?? Config::RUTA_RAIZ;
    $ruta = rtrim($ruta, '/') . '/';
    $key  = $ruta . $filename;

    $s3     = Config::getS3();
    $bucket = Config::BUCKET;

    // Comando y URL presignada (5 minutos)
    $cmd = $s3->getCommand('PutObject', [
        'Bucket'      => $bucket,
        'Key'         => $key,
        'ContentType' => $mime,
        'ACL'         => 'private',
        // 'Metadata'  => ['origen' => 'grabadora-web']
    ]);

    $request = $s3->createPresignedRequest($cmd, '+5 minutes');
    $url = (string) $request->getUri();

    echo json_encode(['ok'=>true, 'url'=>$url, 'key'=>$key, 'bucket'=>$bucket, 'mime'=>$mime]);
} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'AWS: '.$e->getAwsErrorMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
