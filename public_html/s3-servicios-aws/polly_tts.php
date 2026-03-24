<?php
/**
 * polly_tts.php
 * Genera TTS con Amazon Polly y guarda el audio en S3
 * en la MISMA carpeta del TXT origen.
 *
 * FileS3:
 * - Nombre      => nombre visible del archivo
 * - Encriptado  => key completa en S3
 * - Tamano      => bytes
 * - Metadatos   => JSON
 * - Ruta        => carpeta S3
 * - user_id_    => usuario dueño
 */

session_start();
header('Content-Type: application/json; charset=UTF-8');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';

use Aws\Polly\PollyClient;

try {
    if (empty($_SESSION['usuario'])) {
        throw new Exception('Sesión inválida.');
    }

    if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
        throw new Exception('Conexión a BD no inicializada.');
    }

    $texto      = trim($_POST['texto'] ?? '');
    $voiceId    = trim($_POST['voiceId'] ?? '');
    $engine     = trim($_POST['engine'] ?? 'neural');
    $format     = trim($_POST['format'] ?? 'mp3');
    $sampleRate = trim($_POST['sampleRate'] ?? '22050');
    $fromKey    = trim($_POST['from_key'] ?? ($_POST['fromKey'] ?? ($_POST['pollyArchivoKey'] ?? '')));
    $toS3       = (int)($_POST['to_s3'] ?? 1);

    if ($texto === '') {
        throw new Exception('El texto está vacío.');
    }

    if ($voiceId === '') {
        throw new Exception('No se recibió VoiceId.');
    }

    if ($fromKey === '') {
        throw new Exception('No se recibió la key S3 del archivo origen.');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception('No se encontró user_id en la sesión.');
    }

    switch ($format) {
        case 'mp3':
            $finalExt = 'mp3';
            $contentType = 'audio/mpeg';
            break;
        case 'ogg_vorbis':
            $finalExt = 'ogg';
            $contentType = 'audio/ogg';
            break;
        case 'pcm':
            $finalExt = 'pcm';
            $contentType = 'audio/pcm';
            break;
        default:
            $format = 'mp3';
            $finalExt = 'mp3';
            $contentType = 'audio/mpeg';
            break;
    }

    $s3     = Config::getS3();
    $creds  = $s3->getCredentials()->wait();
    $region = $s3->getRegion();

    $polly = new PollyClient([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => $creds
    ]);

    $manager = new S3Manager();
    $bucket  = $manager->getBucket();

    // MISMA CARPETA DEL TXT ORIGEN
    $dir     = rtrim(dirname($fromKey), '/') . '/';
    $encBase = pathinfo(basename($fromKey), PATHINFO_FILENAME);
    $destKey = $dir . $encBase . '.' . $finalExt;

    // Buscar nombre visible/original del TXT para reutilizarlo con nueva extensión
    $nombreOriginalTxt = null;

    $stmt = $db_connection->prepare("
        SELECT Nombre
        FROM FileS3
        WHERE Encriptado = ? AND user_id_ = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception('Error al preparar SELECT origen: ' . $db_connection->error);
    }

    $stmt->bind_param("si", $fromKey, $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error al ejecutar SELECT origen: ' . $error);
    }

    $stmt->bind_result($nombreOriginalTxt);
    $stmt->fetch();
    $stmt->close();

    if (!empty($nombreOriginalTxt)) {
        $nombreOriginal = preg_replace('/\.[^.]+$/', '.' . $finalExt, $nombreOriginalTxt);
    } else {
        $nombreOriginal = $encBase . '.' . $finalExt;
    }

    $params = [
        'Text'         => $texto,
        'VoiceId'      => $voiceId,
        'Engine'       => $engine,
        'OutputFormat' => $format,
        'SampleRate'   => $sampleRate
    ];

    try {
        $res = $polly->synthesizeSpeech($params);
    } catch (\Aws\Exception\AwsException $e) {
        if (
            stripos($e->getMessage(), 'does not support the selected engine') !== false &&
            $engine === 'neural'
        ) {
            $params['Engine'] = 'standard';
            $res = $polly->synthesizeSpeech($params);
        } else {
            throw $e;
        }
    }

    $audioStream = $res->get('AudioStream');
    $audioBytes  = (string)$audioStream;
    $tamanoBytes = strlen($audioBytes);

    if ($tamanoBytes <= 0) {
        throw new Exception('Polly no devolvió contenido de audio.');
    }

$db_status  = 'no_intentado';
$db_message = '';
$db_error   = '';

// Guardar en S3
if ($toS3 === 1) {
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $destKey,
        'Body'        => $audioBytes,
        'ACL'         => 'private',
        'ContentType' => $contentType,
        'Metadata'    => [
            'origin'       => $fromKey,
            'voiceid'      => $voiceId,
            'engine'       => $params['Engine'],
            'format'       => $format,
            'sample_rate'  => $sampleRate
        ]
    ]);
}

// Metadatos para BD
$metadatosArray = [
    'tipo'         => $contentType,
    'servicio'     => 'Amazon Polly',
    'engine'       => $params['Engine'],
    'voiceId'      => $voiceId,
    'format'       => $format,
    'sampleRate'   => $sampleRate,
    'origen'       => $fromKey,
    'destino'      => $destKey,
    'ruta'         => $dir,
    'tamano_bytes' => $tamanoBytes,
    'fecha'        => date('Y-m-d'),
    'hora'         => date('H:i:s')
];

$metadatosJSON = json_encode($metadatosArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($metadatosJSON === false) {
    throw new Exception('Error al generar JSON de metadatos.');
}

// Insertar en BD
$db_status  = 'no_intentado';
$db_message = '';
$db_error   = '';

if ($toS3 === 1) {
    $encriptado = $destKey; // key real del archivo en S3

    $stmt = $db_connection->prepare("
        INSERT INTO FileS3
        (Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, user_id_)
        VALUES (?, ?, ?, ?, ?, 1, 'normal', ?)
    ");

    if (!$stmt) {
        $db_status  = 'error_prepare';
        $db_message = 'No se pudo preparar el INSERT en FileS3.';
        $db_error   = $db_connection->error;
    } else {
        $stmt->bind_param(
            'ssissi',
            $nombreOriginal,
            $encriptado,
            $tamanoBytes,
            $metadatosJSON,
            $dir,
            $userId
        );

        if ($stmt->execute()) {
            $db_status  = 'insertado';
            $db_message = 'Registro insertado correctamente en FileS3.';
        } else {
            if ((int)$stmt->errno === 1062) {
                $db_status  = 'duplicado';
                $db_message = 'El registro ya existía en FileS3.';
                $db_error   = $stmt->error;
            } else {
                $db_status  = 'error_insert';
                $db_message = 'Error al insertar en FileS3.';
                $db_error   = $stmt->error;
            }
        }

        $stmt->close();
    }
}

    $metadatosArray = [
        'tipo'         => $contentType,
        'servicio'     => 'Amazon Polly',
        'engine'       => $params['Engine'],
        'voiceId'      => $voiceId,
        'format'       => $format,
        'sampleRate'   => $sampleRate,
        'origen'       => $fromKey,
        'destino'      => $destKey,
        'ruta'         => $dir,
        'tamano_bytes' => $tamanoBytes,
        'fecha'        => date('Y-m-d'),
        'hora'         => date('H:i:s')
    ];

    $metadatosJSON = json_encode($metadatosArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($metadatosJSON === false) {
        throw new Exception('Error al generar JSON de metadatos.');
    }

    if ($toS3 === 1) {
        // Encriptado en tu tabla = KEY COMPLETA S3
        $encriptado = $destKey;

        $exists = false;

        $stmt = $db_connection->prepare("
            SELECT id_
            FROM FileS3
            WHERE Encriptado = ? AND user_id_ = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new Exception('Error al preparar SELECT existencia: ' . $db_connection->error);
        }

        $stmt->bind_param("si", $encriptado, $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Error al verificar existencia en FileS3: ' . $error);
        }

        $stmt->store_result();
        $exists = ($stmt->num_rows > 0);
        $stmt->close();

        if ($exists) {
            $stmt = $db_connection->prepare("
                UPDATE FileS3
                SET Nombre = ?, Tamano = ?, Metadatos = ?, Ruta = ?
                WHERE Encriptado = ? AND user_id_ = ?
            ");
            if (!$stmt) {
                throw new Exception('Error al preparar UPDATE FileS3: ' . $db_connection->error);
            }

            $stmt->bind_param(
                "sisssi",
                $nombreOriginal,
                $tamanoBytes,
                $metadatosJSON,
                $dir,
                $encriptado,
                $userId
            );

            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new Exception('Error al actualizar FileS3: ' . $error);
            }

            $stmt->close();
        } else {
            $stmt = $db_connection->prepare("
                INSERT INTO FileS3
                (Nombre, Encriptado, Tamano, Metadatos, Ruta, user_id_)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                throw new Exception('Error al preparar INSERT FileS3: ' . $db_connection->error);
            }

            $stmt->bind_param(
                "ssissi",
                $nombreOriginal,
                $encriptado,
                $tamanoBytes,
                $metadatosJSON,
                $dir,
                $userId
            );

            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new Exception('Error al insertar en FileS3: ' . $error);
            }

            $stmt->close();
        }
    }

    echo json_encode([
    'ok'          => true,
    'mode'        => $toS3 ? 's3' : 'inline',
    's3_key'      => $toS3 ? $destKey : null,
    'filename'    => basename($destKey),
    'nombre'      => $nombreOriginal,
    'ruta'        => $dir,
    'audioBase64' => base64_encode($audioBytes),
    'contentType' => $contentType,
    'db_status'   => $db_status,
    'db_message'  => $db_message,
    'db_error'    => $db_error
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