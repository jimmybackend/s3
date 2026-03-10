<?php
require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

$tx_archivoKey = $_POST['archivo']  ?? null;
$tx_language   = $_POST['language'] ?? 'es-ES';

if (!$tx_archivoKey) { echo json_encode(['error' => 'Falta parámetro archivo']); exit; }

$tx_ext = strtolower(pathinfo($tx_archivoKey, PATHINFO_EXTENSION));

// ✅ acepta opus pero lo mapeamos a ogg para el parámetro MediaFormat
$tx_formatos = ['mp3','mp4','wav','flac','ogg','amr','webm','m4a','opus'];
if (!in_array($tx_ext, $tx_formatos, true)) {
  echo json_encode(['error' => 'Formato no soportado para transcripción', 'ext' => $tx_ext]); exit;
}
$tx_mediaFormat = ($tx_ext === 'opus') ? 'ogg' : $tx_ext;

try {
    $transcribe = new Aws\TranscribeService\TranscribeServiceClient([
        'version'     => 'latest',
        'region'      => Config::REGION,
        'credentials' => [
            'key'    => Config::ACCESS_KEY,
            'secret' => Config::SECRET_KEY,
        ],
    ]);

    $jobNameBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', basename($tx_archivoKey));
    $jobName = $jobNameBase . '_' . date('YmdHis');

    $params = [
        'TranscriptionJobName' => $jobName,
        'Media'       => ['MediaFileUri' => 's3://' . Config::BUCKET . '/' . $tx_archivoKey],
        'MediaFormat' => $tx_mediaFormat, // ← aquí ya va "ogg" si la extensión era .opus
    ];

    if ($tx_language === 'auto') {
        $params['IdentifyLanguage'] = true;
    } else {
        $params['LanguageCode'] = $tx_language; // ej. es-ES (por defecto)
    }

    $res = $transcribe->startTranscriptionJob($params);

    echo json_encode([
        'ok'      => true,
        'jobName' => $jobName,
        'status'  => $res['TranscriptionJob']['TranscriptionJobStatus'] ?? null
    ]);

} catch (\Aws\Exception\AwsException $e) {
    echo json_encode(['error' => $e->getAwsErrorMessage() ?: $e->getMessage()]);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
