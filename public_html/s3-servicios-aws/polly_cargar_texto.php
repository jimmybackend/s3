<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

try {
    if (empty($_SESSION['usuario'])) {
        throw new Exception('Sesión inválida.');
    }

    $archivoKey = trim((string)($_POST['archivo'] ?? ''));

    if ($archivoKey === '') {
        throw new Exception('No se recibió el archivo.');
    }

    $ext = strtolower(pathinfo($archivoKey, PATHINFO_EXTENSION));
    $permitidas = ['txt', 'md'];

    if (!in_array($ext, $permitidas, true)) {
        throw new Exception('Solo se pueden cargar archivos TXT o MD en Polly.');
    }

    $s3 = Config::getS3();
    $obj = $s3->getObject([
        'Bucket' => Config::BUCKET,
        'Key'    => $archivoKey
    ]);

    $body = (string)$obj['Body'];

    if (!mb_detect_encoding($body, 'UTF-8', true)) {
        $body = mb_convert_encoding($body, 'UTF-8');
    }

    echo json_encode([
        'ok'      => true,
        'archivo' => $archivoKey,
        'texto'   => $body
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
    exit;
}