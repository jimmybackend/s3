<?php
require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

$archivoKey = $_POST['archivo'] ?? null;
$target     = $_POST['target']  ?? 'es';
$source     = $_POST['source']  ?? 'auto';

if (!$archivoKey) {
    echo json_encode(['error' => 'No se recibió el parámetro archivo', 'debug' => $_POST]); exit;
}

$ext = strtolower(pathinfo($archivoKey, PATHINFO_EXTENSION));
$soportadasTextract = ['pdf','jpg','jpeg','png','tif','tiff'];
$soportadasTexto    = ['txt','md']; // agrega las que quieras traducir como texto plano

try {
    // 1) Obtener texto original (de S3 o via Textract)
    $textoOriginal = '';

    if (in_array($ext, $soportadasTexto, true)) {
        // Leer objeto desde S3 como texto
        $s3 = Config::getS3();
        $obj = $s3->getObject(['Bucket' => Config::BUCKET, 'Key' => $archivoKey]);
        $body = (string)$obj['Body'];
        // Asegurar UTF-8
        if (!mb_detect_encoding($body, 'UTF-8', true)) {
            $body = mb_convert_encoding($body, 'UTF-8');
        }
        $textoOriginal = $body;

    } elseif (in_array($ext, $soportadasTextract, true)) {
        // Extraer texto con Textract
        $textract = new Aws\Textract\TextractClient([
            'version'     => 'latest',
            'region'      => Config::REGION,
            'credentials' => [
                'key'    => Config::ACCESS_KEY,
                'secret' => Config::SECRET_KEY,
            ],
        ]);
        $res = $textract->detectDocumentText([
            'Document' => ['S3Object' => ['Bucket' => Config::BUCKET, 'Name' => $archivoKey]]
        ]);

        $lineas = [];
        if (!empty($res['Blocks'])) {
            foreach ($res['Blocks'] as $b) {
                if (!empty($b['BlockType']) && $b['BlockType'] === 'LINE' && isset($b['Text'])) {
                    $lineas[] = $b['Text'];
                }
            }
        }
        $textoOriginal = implode("\n", $lineas);

    } else {
        echo json_encode(['error' => 'Extensión no soportada para traducción', 'ext' => $ext]); exit;
    }

    // 2) Dividir en trozos (TranslateText máx 10,000 bytes)
    $chunks = [];
    $max = 9500; // margen de seguridad
    $cursor = 0;
    $len = mb_strlen($textoOriginal, 'UTF-8');

    while ($cursor < $len) {
        $slice = mb_substr($textoOriginal, $cursor, 4000, 'UTF-8'); // aprox; ajustamos por bytes
        // Ensayar hasta que el trozo no exceda $max bytes
        while (strlen($slice) > $max) {
            $slice = mb_substr($slice, 0, mb_strlen($slice, 'UTF-8') - 200, 'UTF-8');
        }
        $chunks[] = $slice;
        $cursor += mb_strlen($slice, 'UTF-8');
    }

    // 3) Traducir cada trozo con Amazon Translate (source 'auto' válido)
    $translate = new Aws\Translate\TranslateClient([
        'version'     => 'latest',
        'region'      => Config::REGION,
        'credentials' => [
            'key'    => Config::ACCESS_KEY,
            'secret' => Config::SECRET_KEY,
        ],
    ]);

    $traduccion = '';
    foreach ($chunks as $c) {
        if ($c === '') continue;
        $resp = $translate->translateText([
            'Text'               => $c,
            'SourceLanguageCode' => $source, // 'auto' soportado por TranslateText
            'TargetLanguageCode' => $target,
        ]);
        $traduccion .= $resp['TranslatedText'] . "\n";
    }

    echo json_encode([
        'ok'           => true,
        'archivo'      => $archivoKey,
        'target'       => $target,
        'sourceUsed'   => $source,
        'traduccion'   => trim($traduccion)
    ]);

} catch (\Aws\Exception\AwsException $e) {
    echo json_encode(['error' => $e->getAwsErrorMessage() ?: $e->getMessage()]);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
