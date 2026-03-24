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
    // ===== Sesión =====
    if (empty($_SESSION['usuario'])) {
        throw new Exception('Sesión inválida.');
    }

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        throw new Exception('Usuario no identificado.');
    }

    // ===== Inputs =====
    $key       = trim(isset($_POST['archivo']) ? $_POST['archivo'] : (isset($_POST['key']) ? $_POST['key'] : ''));
    $minConf   = isset($_POST['min_conf']) ? (float)$_POST['min_conf'] : 70.0;
    $maxLabels = isset($_POST['max_labels']) ? (int)$_POST['max_labels'] : 50;

    if ($key === '') {
        throw new Exception('Falta el parámetro "archivo" o "key".');
    }

    // ===== Separar Ruta y Nombre desde la key real =====
    $nombre = basename($key);
    $ruta   = substr($key, 0, strlen($key) - strlen($nombre));

    if ($ruta === false) {
        $ruta = '';
    }

    // Asegurar barra final en Ruta si existe
    if ($ruta !== '' && substr($ruta, -1) !== '/') {
        $ruta .= '/';
    }

    // ===== Clientes AWS =====
    $s3     = Config::getS3();
    $creds  = $s3->getCredentials()->wait();
    $region = $s3->getRegion();

    $rek = new RekognitionClient(array(
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => $creds,
    ));

    $manager = new S3Manager();
    $bucket  = $manager->getBucket();

    // ===== 1) Etiquetas =====
    $labelsResp = $rek->detectLabels(array(
        'Image' => array(
            'S3Object' => array(
                'Bucket' => $bucket,
                'Name'   => $key,
            ),
        ),
        'MaxLabels'     => $maxLabels,
        'MinConfidence' => $minConf,
    ));

    $labels = array();

    foreach ((array)$labelsResp->get('Labels') as $lab) {
        $parents = array();

        if (!empty($lab['Parents']) && is_array($lab['Parents'])) {
            foreach ($lab['Parents'] as $p) {
                if (isset($p['Name'])) {
                    $parents[] = (string)$p['Name'];
                }
            }
        }

        $instancesCount = 0;
        if (!empty($lab['Instances']) && is_array($lab['Instances'])) {
            $instancesCount = count($lab['Instances']);
        }

        $labels[] = array(
            'Name'       => isset($lab['Name']) ? (string)$lab['Name'] : '',
            'Confidence' => isset($lab['Confidence']) ? (float)$lab['Confidence'] : null,
            'Parents'    => $parents,
            'Instances'  => $instancesCount,
        );
    }

    // ===== 2) Moderación =====
    $modResp = $rek->detectModerationLabels(array(
        'Image' => array(
            'S3Object' => array(
                'Bucket' => $bucket,
                'Name'   => $key,
            ),
        ),
        'MinConfidence' => $minConf,
    ));

    $moderation = array();

    foreach ((array)$modResp->get('ModerationLabels') as $ml) {
        $moderation[] = array(
            'Name'       => isset($ml['Name']) ? (string)$ml['Name'] : '',
            'ParentName' => isset($ml['ParentName']) ? (string)$ml['ParentName'] : '',
            'Confidence' => isset($ml['Confidence']) ? (float)$ml['Confidence'] : null,
        );
    }

    // ===== 3) Guardar SIEMPRE en FileS3.Metadatos usando Ruta + Nombre =====
    $saved = false;
    $meta  = array();

    $sqlSel = "SELECT Metadatos 
               FROM FileS3 
               WHERE Ruta = ? AND Nombre = ? AND user_id_ = ? 
               LIMIT 1";

    if (!$stmt = $db_connection->prepare($sqlSel)) {
        throw new Exception('No se pudo preparar la consulta de lectura.');
    }

    $stmt->bind_param("ssi", $ruta, $nombre, $userId);
    $stmt->execute();
    $stmt->bind_result($metasDB);
    $rowFound = $stmt->fetch();
    $stmt->close();

    if (!$rowFound) {
        throw new Exception('No se encontró el archivo en FileS3 usando Ruta y Nombre.');
    }

    if (!empty($metasDB)) {
        $tmp = json_decode($metasDB, true);
        if (is_array($tmp)) {
            $meta = $tmp;
        }
    }

    $meta['Rekognition'] = array(
        'ts'         => date('c'),
        'Bucket'     => $bucket,
        'S3Key'      => $key,
        'Ruta'       => $ruta,
        'Nombre'     => $nombre,
        'MinConf'    => $minConf,
        'MaxLabels'  => $maxLabels,
        'Labels'     => $labels,
        'Moderation' => $moderation
    );

    $newJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
    if ($newJson === false) {
        throw new Exception('No se pudo convertir a JSON los metadatos.');
    }

    $sqlUpd = "UPDATE FileS3 
               SET Metadatos = ? 
               WHERE Ruta = ? AND Nombre = ? AND user_id_ = ? 
               LIMIT 1";

    if (!$up = $db_connection->prepare($sqlUpd)) {
        throw new Exception('No se pudo preparar la consulta de actualización.');
    }

    $up->bind_param("sssi", $newJson, $ruta, $nombre, $userId);
    $up->execute();
    $saved = ($up->affected_rows >= 0);
    $up->close();

    echo json_encode(array(
        'ok'         => true,
        'bucket'     => $bucket,
        's3_key'     => $key,
        'ruta'       => $ruta,
        'nombre'     => $nombre,
        'min_conf'   => $minConf,
        'max_labels' => $maxLabels,
        'labels'     => $labels,
        'moderation' => $moderation,
        'saved'      => $saved,
        'message'    => 'Análisis realizado y metadatos guardados correctamente.'
    ), JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(array(
        'ok'    => false,
        'error' => $e->getMessage()
    ));
}