<?php
require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

$jobName = $_POST['jobName'] ?? null;
if (!$jobName) { echo json_encode(['error' => 'Falta parámetro jobName']); exit; }

try {
    $transcribe = new Aws\TranscribeService\TranscribeServiceClient([
        'version'     => 'latest',
        'region'      => Config::REGION,
        'credentials' => [
            'key'    => Config::ACCESS_KEY,
            'secret' => Config::SECRET_KEY,
        ],
    ]);

    $res = $transcribe->getTranscriptionJob([
        'TranscriptionJobName' => $jobName
    ]);

    $job = $res['TranscriptionJob'] ?? null;
    if (!$job) { echo json_encode(['error' => 'Job no encontrado']); exit; }

    $status = $job['TranscriptionJobStatus'];
    if ($status !== 'COMPLETED') {
        echo json_encode(['status' => $status, 'message' => $job['FailureReason'] ?? null]);
        exit;
    }

    // Si COMPLETED, descargar el JSON con el texto
    $uri = $job['Transcript']['TranscriptFileUri'] ?? null;
    if (!$uri) { echo json_encode(['status' => $status, 'error' => 'Sin TranscriptFileUri']); exit; }

    // Descargar y parsear
    $json = file_get_contents($uri);
    if ($json === false) { echo json_encode(['status' => $status, 'error' => 'No se pudo leer transcript URI']); exit; }

    $payload = json_decode($json, true);
    $texto = '';
    if (isset($payload['results']['transcripts'][0]['transcript'])) {
        $texto = $payload['results']['transcripts'][0]['transcript'];
    }

    echo json_encode(['status' => 'COMPLETED', 'texto' => $texto]);

} catch (\Aws\Exception\AwsException $e) {
    echo json_encode(['error' => $e->getAwsErrorMessage() ?: $e->getMessage()]);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
