<?php
// upload_audio_recording.php
// Guarda la grabación en la carpeta actual (según $_SESSION['ruta_actual']) dentro del bucket S3

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\Exception\AwsException;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
        exit;
    }

    if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No se recibió el archivo de audio']);
        exit;
    }

    $mime = isset($_POST['mime']) ? (string)$_POST['mime'] : '';
    if (strpos($mime, 'audio/') !== 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'MIME inválido (no es audio)']);
        exit;
    }

    // Nombre final
    $filename = isset($_POST['filename']) ? (string)$_POST['filename'] : '';
    $filename = trim($filename);

    // Sanea nombre (evita caracteres peligrosos)
    $filename = preg_replace('/[\\\\\\/:*?"<>|]+/', ' ', $filename);
    $filename = preg_replace('/\\s+/', ' ', $filename);
    if ($filename === '') {
        // fallback por si llega vacío
        $ext = '.webm';
        if (stripos($mime, 'ogg') !== false) $ext = '.ogg';
        elseif (stripos($mime, 'mp4') !== false) $ext = '.m4a';
        $filename = 'Grabacion_' . date('Y-m-d_H-i-s') . $ext;
    }

    // Ruta actual desde sesión
    $ruta = isset($_SESSION['ruta_actual']) ? $_SESSION['ruta_actual'] : Config::RUTA_RAIZ;
    $ruta = rtrim($ruta, '/') . '/';

    $key = $ruta . $filename;

    // Validaciones de tamaño (opcional, ajusta a tu gusto)
    $maxBytes = 100 * 1024 * 1024; // 100 MB
    if (filesize($_FILES['audio']['tmp_name']) > $maxBytes) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'El archivo es demasiado grande (máx 100MB)']);
        exit;
    }

    // Sube a S3
    $s3 = Config::getS3();
    $bucket = Config::BUCKET;

    $args = [
        'Bucket'      => $bucket,
        'Key'         => $key,
        'Body'        => fopen($_FILES['audio']['tmp_name'], 'rb'),
        'ContentType' => $mime,
        'ACL'         => 'private', // o 'public-read' si corresponde a tu caso
        // 'Metadata'  => [ 'origen' => 'grabadora-web' ],
    ];

    $result = $s3->putObject($args);

    echo json_encode([
        'ok'        => true,
        'key'       => $key,
        'bucket'    => $bucket,
        'mime'      => $mime,
        'versionId' => isset($result['VersionId']) ? $result['VersionId'] : null,
    ]);
} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'AWS: '.$e->getAwsErrorMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
