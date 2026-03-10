<?php
// rekognition_labels.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';


use Aws\Rekognition\RekognitionClient;

try {
    // --- Validaciones básicas de sesión ---
    if (empty($_SESSION['usuario'])) {
        throw new Exception('Sesión inválida.');
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception('Usuario no identificado.');
    }

    // --- Inputs ---
    // archivo: S3 key completa de la imagen (ej: "Data/Imágenes/foto123.jpg")
    $key         = trim($_POST['archivo'] ?? $_POST['key'] ?? '');
    $save        = (int)($_POST['save'] ?? 0);           // 1 = guardar en BD dentro de Metadatos
    $minConf     = (float)($_POST['min_conf'] ?? 70.0);  // confianza mínima
    $maxLabels   = (int)($_POST['max_labels'] ?? 50);

    if ($key === '') {
        throw new Exception('Falta el parámetro "archivo" (S3 key).');
    }

    // --- S3 + Rekognition clients (mismos creds/region que S3) ---
    $s3      = Config::getS3();
    $creds   = $s3->getCredentials()->wait();
    $region  = $s3->getRegion();

    $rek = new RekognitionClient([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => $creds,
    ]);

    $manager = new S3Manager();
    $bucket  = $manager->getBucket();

    // --- Llamadas a Rekognition ---
    // 1) Etiquetas (objetos/escenas)
    $labelsResp = $rek->detectLabels([
        'Image' => [
            'S3Object' => [
                'Bucket' => $bucket,
                'Name'   => $key,
            ],
        ],
        'MaxLabels'     => $maxLabels,
        'MinConfidence' => $minConf,
    ]);

    $labels = [];
    foreach ((array)$labelsResp->get('Labels') as $lab) {
        $labels[] = [
            'Name'        => (string)($lab['Name'] ?? ''),
            'Confidence'  => isset($lab['Confidence']) ? (float)$lab['Confidence'] : null,
            'Parents'     => array_map(fn($p) => (string)$p['Name'], (array)($lab['Parents'] ?? [])),
            'Instances'   => count((array)($lab['Instances'] ?? [])), // solo conteo (las cajas pueden ser muchas)
        ];
    }

    // 2) Moderación (contenido sensible)
    $modResp = $rek->detectModerationLabels([
        'Image' => [
            'S3Object' => [
                'Bucket' => $bucket,
                'Name'   => $key,
            ],
        ],
        'MinConfidence' => $minConf,
    ]);

    $moderation = [];
    foreach ((array)$modResp->get('ModerationLabels') as $ml) {
        $moderation[] = [
            'Name'        => (string)($ml['Name'] ?? ''),
            'ParentName'  => (string)($ml['ParentName'] ?? ''),
            'Confidence'  => isset($ml['Confidence']) ? (float)$ml['Confidence'] : null,
        ];
    }

    // --- Opcional: guardar en BD dentro de FileS3.Metadatos ---
    // Buscamos por Encriptado = basename($key), y user_id_ = sesión
    $saved = false;
    if ($save === 1) {
        $enc = basename($key);

        // Traer metadatos actuales
        $sqlSel = "SELECT Metadatos FROM FileS3 WHERE Encriptado = ? AND user_id_ = ? LIMIT 1";
        if ($stmt = $db_connection->prepare($sqlSel)) {
            $stmt->bind_param("si", $enc, $userId);
            $stmt->execute();
            $stmt->bind_result($metasDB);
            $rowFound = $stmt->fetch();
            $stmt->close();

            if ($rowFound) {
                // Mezclar/actualizar JSON de metadatos
                $meta = [];
                if (!empty($metasDB)) {
                    $tmp = json_decode($metasDB, true);
                    if (is_array($tmp)) $meta = $tmp;
                }
                $meta['Rekognition'] = [
                    'ts'          => date('c'),
                    'MinConf'     => $minConf,
                    'MaxLabels'   => $maxLabels,
                    'Labels'      => $labels,
                    'Moderation'  => $moderation,
                ];
                $newJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

                $sqlUpd = "UPDATE FileS3 SET Metadatos = ? WHERE Encriptado = ? AND user_id_ = ? LIMIT 1";
                if ($up = $db_connection->prepare($sqlUpd)) {
                    $up->bind_param("ssi", $newJson, $enc, $userId);
                    $up->execute();
                    $up->close();
                    $saved = true;
                }
            }
            // Si no existe el registro, no insertamos (para no romper tu flujo actual)
        }
    }

    echo json_encode([
        'ok'          => true,
        'bucket'      => $bucket,
        's3_key'      => $key,
        'min_conf'    => $minConf,
        'max_labels'  => $maxLabels,
        'labels'      => $labels,
        'moderation'  => $moderation,
        'saved'       => $saved,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
