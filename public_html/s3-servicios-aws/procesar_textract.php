<?php
require_once '../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Acepta 'archivo' o 'archivoTextract' por compatibilidad
$archivoKey = $_POST['archivo'] ?? $_POST['archivoTextract'] ?? null;
if (!$archivoKey) {
    echo json_encode(['error' => 'No se recibió el parámetro del archivo', 'debug' => ['post' => $_POST]]);
    exit;
}

// Extensiones soportadas por Textract (modo síncrono)
$ext = strtolower(pathinfo($archivoKey, PATHINFO_EXTENSION));
$soportadas = ['jpg','jpeg','png','tif','tiff','pdf'];
if (!in_array($ext, $soportadas, true)) {
    echo json_encode([
        'error' => 'Extensión no soportada para Textract',
        'archivo' => $archivoKey,
        'ext' => $ext,
        'soportadas' => $soportadas
    ]);
    exit;
}

try {
    // ⚠️ Credenciales explícitas desde tu Config.php
    $textract = new Aws\Textract\TextractClient([
        'version'     => 'latest',
        'region'      => Config::REGION,
        'credentials' => [
            'key'    => Config::ACCESS_KEY,
            'secret' => Config::SECRET_KEY,
        ],
        // opcional: timeouts más amigables
        // 'http' => ['connect_timeout' => 3, 'timeout' => 30],
    ]);

    $result = $textract->detectDocumentText([
        'Document' => [
            'S3Object' => [
                'Bucket' => Config::BUCKET,
                'Name'   => $archivoKey,
            ],
        ],
    ]);

    $lineas = [];
    if (!empty($result['Blocks'])) {
        foreach ($result['Blocks'] as $b) {
            if (!empty($b['BlockType']) && $b['BlockType'] === 'LINE' && isset($b['Text'])) {
                $lineas[] = $b['Text'];
            }
        }
    }

    echo json_encode([
        'ok'      => true,
        'archivo' => $archivoKey,
        'texto'   => $lineas,
        'textoJ'  => implode("\n", $lineas),
    ]);

} catch (\Aws\Exception\AwsException $e) {
    // Error específico de AWS
    echo json_encode(['error' => $e->getAwsErrorMessage() ?: $e->getMessage()]);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
