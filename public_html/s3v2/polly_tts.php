<?php
/**
 * polly_tts.php
 * Genera TTS con Amazon Polly y guarda (o sobrescribe) el audio en S3
 * con el MISMO nombre encriptado del TXT (cambiando solo la extensión).
 * En BD (tabla FileS3 sin columna id): UPSERT por (Encriptado, user_id_).
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
    // --- Validaciones ---
    if (empty($_SESSION['usuario'])) {
        throw new Exception('Sesión inválida.');
    }
    if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
        throw new Exception('Conexión a BD no inicializada.');
    }

    // --- Inputs ---
    $texto      = trim($_POST['texto']      ?? '');
    $voiceId    = trim($_POST['voiceId']    ?? '');
    $engine     = trim($_POST['engine']     ?? 'neural');         // neural | standard
    $format     = trim($_POST['format']     ?? 'mp3');            // mp3 | ogg_vorbis | pcm
    $sampleRate = trim($_POST['sampleRate'] ?? '22050');          // 8000|16000|22050|24000
    $fromKey    = trim($_POST['from_key']   ?? ($_POST['fromKey'] ?? ($_POST['pollyArchivoKey'] ?? '')));
    $toS3       = (int)($_POST['to_s3']     ?? 1);

    if ($texto === '' || $voiceId === '') {
        throw new Exception('Parámetros incompletos: texto y/o voz vacíos.');
    }

    // Normaliza extensión final
    if ($format === 'mp3') {
        $finalExt = 'mp3';
    } elseif ($format === 'ogg_vorbis') {
        $finalExt = 'ogg';
    } elseif ($format === 'pcm') {
        $finalExt = 'pcm'; // PCM crudo (no WAV)
    } else {
        $format   = 'mp3';
        $finalExt = 'mp3';
    }

    // --- AWS clients ---
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

    // --- Derivar carpeta y hash base destino ---
    $nombreOriginalTxt = null;

    if ($fromKey !== '') {
        // Misma carpeta del TXT y mismo hash base
        $dir     = rtrim(dirname($fromKey), '/') . '/';
        $encBase = pathinfo(basename($fromKey), PATHINFO_FILENAME); // hash sin extensión

        // Buscar nombre real del TXT para derivar nombre real del audio
        if (isset($_SESSION['user_id'])) {
            $uid   = (int)$_SESSION['user_id'];
            $encTxt = basename($fromKey); // ej: abc123.txt
            $stmt = $db_connection->prepare(
                "SELECT Nombre FROM FileS3 WHERE Encriptado = ? AND user_id_ = ? LIMIT 1"
            );
            $stmt->bind_param("si", $encTxt, $uid);
            $stmt->execute();
            $stmt->bind_result($nombreOriginalTxt);
            $stmt->fetch();
            $stmt->close();
        }
    } else {
        // Sin referencia: usar ruta actual de sesión y un hash aleatorio
        $dir     = rtrim($_SESSION['ruta_actual'] ?? Config::RUTA_RAIZ, '/') . '/';
        $encBase = bin2hex(random_bytes(8));
    }

    $destKey = $dir . $encBase . '.' . $finalExt;

    // Nombre real para la columna "Nombre"
    if ($nombreOriginalTxt) {
        $nombreOriginal = preg_replace('/\.[^.]+$/', '.' . $finalExt, $nombreOriginalTxt);
    } else {
        $nombreOriginal = 'Polly-' . date('Ymd-His') . '.' . $finalExt;
    }

    // --- Sintetizar ---
    $params = [
        'Text'         => $texto,
        'VoiceId'      => $voiceId,
        'Engine'       => $engine,         // hará fallback abajo si Neural no es soportado
        'OutputFormat' => $format,         // mp3 | ogg_vorbis | pcm
        'SampleRate'   => $sampleRate
    ];

    try {
        $res = $polly->synthesizeSpeech($params);
    } catch (\Aws\Exception\AwsException $e) {
        if (stripos($e->getMessage(), 'does not support the selected engine') !== false && $engine === 'neural') {
            // fallback a standard
            $params['Engine'] = 'standard';
            $res = $polly->synthesizeSpeech($params);
        } else {
            throw $e;
        }
    }

    $audioStream = $res->get('AudioStream');
    $audioBytes  = (string)$audioStream;
    $tamanoBytes = strlen($audioBytes);

    // --- Guardar en S3 (sobrescribe por Key) ---
    $contentType = ($finalExt === 'mp3') ? 'audio/mpeg'
                 : (($finalExt === 'ogg') ? 'audio/ogg'
                 : (($finalExt === 'pcm') ? 'audio/pcm' : 'application/octet-stream'));

    if ($toS3) {
        $s3->putObject([
            'Bucket'      => $bucket,
            'Key'         => $destKey,
            'Body'        => $audioBytes,
            'ACL'         => 'private',
            'ContentType' => $contentType,
            'Metadata'    => [
                'origin'  => $fromKey,
                'engine'  => $params['Engine'],
                'voice'   => $voiceId,
                'format'  => $format,
                'rate'    => $sampleRate,
            ]
        ]);
        // putObject ya sobrescribe si la Key existe: no hay que borrar antes.
    }

    // --- BD: UPSERT por (Encriptado, user_id_) sin columna id ---
    $metadatosArray = [
        'Servicio' => 'Polly',
        'Engine'   => $params['Engine'],
        'VoiceId'  => $voiceId,
        'Format'   => $format,
        'Sample'   => $sampleRate,
        'Origen'   => $fromKey,
        'Ruta'     => $dir,
    ];
    $metadatosJSON = json_encode($metadatosArray, JSON_UNESCAPED_UNICODE);

    $nombreHash = basename($destKey);   // p.ej. abc123.mp3
    $userId     = (int)($_SESSION['user_id'] ?? 0);

    if ($toS3) {
        // ¿Existe para este usuario con ese Encriptado?
        $exists = false;
        $stmt = $db_connection->prepare(
            "SELECT 1 FROM FileS3 WHERE Encriptado = ? AND user_id_ = ? LIMIT 1"
        );
        $stmt->bind_param("si", $nombreHash, $userId);
        $stmt->execute();
        $stmt->bind_result($dummy);
        $exists = $stmt->fetch() ? true : false;
        $stmt->close();

        if ($exists) {
            // UPDATE
            $stmt = $db_connection->prepare(
                "UPDATE FileS3
                   SET Nombre = ?, Metadatos = ?, Tamano = ?
                 WHERE Encriptado = ? AND user_id_ = ?"
            );
            //            s            s            i                s             i
            $stmt->bind_param("ssisi", $nombreOriginal, $metadatosJSON, $tamanoBytes, $nombreHash, $userId);
            $stmt->execute();
            $stmt->close();
        } else {
            // INSERT
            $stmt = $db_connection->prepare(
                "INSERT INTO FileS3 (Nombre, Encriptado, Metadatos, Tamano, user_id_) VALUES (?, ?, ?, ?, ?)"
            );
            //            s       s           s          i      i
            $stmt->bind_param("sssii", $nombreOriginal, $nombreHash, $metadatosJSON, $tamanoBytes, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // --- Respuesta (incluye audioBase64 para previsualizar) ---
    echo json_encode([
        'ok'          => true,
        'mode'        => $toS3 ? 's3' : 'inline',
        's3_key'      => $toS3 ? $destKey : null,
        'filename'    => basename($destKey),
        'audioBase64' => base64_encode($audioBytes),
        'contentType' => $contentType
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
