<?php
require_once '/../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

function tx_json_response(array $data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tx_get_param($key, $default = '') {
    if (isset($_POST[$key])) {
        return trim((string)$_POST[$key]);
    }
    if (isset($_GET[$key])) {
        return trim((string)$_GET[$key]);
    }
    return $default;
}

function tx_fetch_remote_binary($url) {
    $context = stream_context_create([
        'http' => ['timeout' => 60, 'ignore_errors' => true],
        'https' => ['timeout' => 60, 'ignore_errors' => true],
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false || $data === '') {
        throw new Exception('No se pudo descargar el recurso remoto: ' . $url);
    }
    return $data;
}

function tx_normalize_path($path) {
    $path = str_replace('\\', '/', (string)$path);
    $path = preg_replace('#/+#', '/', $path);
    return ltrim($path, '/');
}

function tx_find_file_row(mysqli $db, int $userId, string $archivoInput, string $rutaActual = '') {
    $archivoInput = tx_normalize_path($archivoInput);
    $rutaActual = tx_normalize_path($rutaActual);
    $nombre = basename($archivoInput);
    $rutaInput = '';
    $pos = strrpos($archivoInput, '/');
    if ($pos !== false) {
        $rutaInput = substr($archivoInput, 0, $pos + 1);
    }
    $rutaLookup = $rutaInput !== '' ? $rutaInput : $rutaActual;

    $queries = [
        ['sql' => "SELECT id_, Nombre, Encriptado, Ruta FROM FileS3 WHERE user_id_ = ? AND Encriptado = ? LIMIT 1", 'types' => 'is', 'params' => [$userId, $archivoInput]],
        ['sql' => "SELECT id_, Nombre, Encriptado, Ruta FROM FileS3 WHERE user_id_ = ? AND Nombre = ? AND Ruta = ? LIMIT 1", 'types' => 'iss', 'params' => [$userId, $nombre, $rutaLookup]],
        ['sql' => "SELECT id_, Nombre, Encriptado, Ruta FROM FileS3 WHERE user_id_ = ? AND Nombre = ? ORDER BY id_ DESC LIMIT 1", 'types' => 'is', 'params' => [$userId, $nombre]],
    ];

    foreach ($queries as $q) {
        $stmt = $db->prepare($q['sql']);
        if (!$stmt) {
            throw new Exception('Error al preparar búsqueda en FileS3: ' . $db->error);
        }
        $stmt->bind_param($q['types'], ...$q['params']);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Error al buscar archivo en FileS3: ' . $error);
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $row['Encriptado'] = tx_normalize_path((string)$row['Encriptado']);
            $row['Ruta'] = tx_normalize_path((string)$row['Ruta']);
            return $row;
        }
    }

    throw new Exception('No se encontró el archivo origen en FileS3.');
}

function tx_upsert_generated_file(mysqli $db, int $userId, string $nombre, string $encriptado, int $tamanoBytes, string $metadatosJSON, string $ruta) {
    $stmt = $db->prepare("SELECT id_ FROM FileS3 WHERE Encriptado = ? AND user_id_ = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Error al preparar SELECT existencia: ' . $db->error);
    }
    $stmt->bind_param('si', $encriptado, $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error al verificar existencia en FileS3: ' . $error);
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $id = (int)$row['id_'];
        $stmt = $db->prepare("UPDATE FileS3 SET Nombre = ?, Tamano = ?, Metadatos = ?, Ruta = ?, Found = 1, AccessType = 'normal' WHERE id_ = ? AND user_id_ = ?");
        if (!$stmt) {
            throw new Exception('Error al preparar UPDATE FileS3: ' . $db->error);
        }
        $stmt->bind_param('sissii', $nombre, $tamanoBytes, $metadatosJSON, $ruta, $id, $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Error al actualizar FileS3: ' . $error);
        }
        $stmt->close();
        return 'actualizado';
    }

    $stmt = $db->prepare("INSERT INTO FileS3 (Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, user_id_) VALUES (?, ?, ?, ?, ?, 1, 'normal', ?)");
    if (!$stmt) {
        throw new Exception('Error al preparar INSERT FileS3: ' . $db->error);
    }
    $stmt->bind_param('ssissi', $nombre, $encriptado, $tamanoBytes, $metadatosJSON, $ruta, $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error al insertar en FileS3: ' . $error);
    }
    $stmt->close();
    return 'insertado';
}


function tx_parse_s3_location_from_uri($uri) {
    $uri = trim((string)$uri);
    if ($uri === '') {
        return [null, null];
    }

    if (stripos($uri, 's3://') === 0) {
        $without = substr($uri, 5);
        $pos = strpos($without, '/');
        if ($pos === false) {
            return [null, null];
        }
        return [substr($without, 0, $pos), ltrim(substr($without, $pos + 1), '/')];
    }

    $parts = parse_url($uri);
    if (!is_array($parts)) {
        return [null, null];
    }
    $host = (string)($parts['host'] ?? '');
    $path = ltrim((string)($parts['path'] ?? ''), '/');
    if ($host === '' || $path === '') {
        return [null, null];
    }

    // virtual-hosted-style: bucket.s3.amazonaws.com/key or bucket.s3.us-east-1.amazonaws.com/key
    if (preg_match('/^(.+)\.s3[.-][^.]+\.amazonaws\.com$/i', $host, $m) || preg_match('/^(.+)\.s3\.amazonaws\.com$/i', $host, $m)) {
        return [$m[1], $path];
    }

    // path-style: s3.amazonaws.com/bucket/key or s3.us-east-1.amazonaws.com/bucket/key
    if (preg_match('/^s3[.-][^.]+\.amazonaws\.com$/i', $host) || preg_match('/^s3\.amazonaws\.com$/i', $host)) {
        $pos = strpos($path, '/');
        if ($pos === false) {
            return [null, null];
        }
        return [substr($path, 0, $pos), ltrim(substr($path, $pos + 1), '/')];
    }

    return [null, null];
}

function tx_fetch_remote_binary_smart($s3, string $defaultBucket, string $url) {
    [$urlBucket, $urlKey] = tx_parse_s3_location_from_uri($url);
    if ($urlBucket !== null && $urlKey !== null) {
        try {
            $obj = $s3->getObject([
                'Bucket' => $urlBucket,
                'Key' => $urlKey,
            ]);
            return (string)$obj['Body'];
        } catch (\Throwable $e) {
            // continúa al fallback HTTP
        }
    }

    $data = tx_fetch_remote_binary($url);
    $trimmed = ltrim($data);
    if (stripos($trimmed, '<?xml') === 0 && stripos($trimmed, '<Code>AccessDenied</Code>') !== false) {
        throw new Exception('Amazon devolvió AccessDenied al leer el resultado de transcripción.');
    }
    return $data;
}
function tx_s3_uri_to_key($uri, $expectedBucket = '') {
    $uri = trim((string)$uri);
    if ($uri === '' || stripos($uri, 's3://') !== 0) {
        return '';
    }
    $withoutScheme = substr($uri, 5);
    $pos = strpos($withoutScheme, '/');
    if ($pos === false) {
        return '';
    }
    $bucket = substr($withoutScheme, 0, $pos);
    $key = substr($withoutScheme, $pos + 1);
    if ($expectedBucket !== '' && $bucket !== $expectedBucket) {
        return ltrim($key, '/');
    }
    return ltrim($key, '/');
}

function tx_store_generated_variant($s3, mysqli $db, int $userId, string $bucket, array $sourceRow, string $jobName, string $languageCode, string $status, string $remoteUrl, string $extension, string $contentType, array $extraMeta = []) {
    $body = tx_fetch_remote_binary_smart($s3, $bucket, $remoteUrl);
    $tamanoBytes = strlen($body);
    if ($tamanoBytes <= 0) {
        throw new Exception('El archivo descargado está vacío: ' . $extension);
    }

    $sourceEncrypted = (string)$sourceRow['Encriptado'];
    $sourceNombre = (string)$sourceRow['Nombre'];
    $ruta = (string)$sourceRow['Ruta'];

    $encryptedBase = pathinfo($sourceEncrypted, PATHINFO_FILENAME);
    $visibleBase = pathinfo($sourceNombre, PATHINFO_FILENAME);

    $destKey = $ruta . $encryptedBase . '.' . $extension;
    $destNombre = $visibleBase . '.' . $extension;

    $s3->putObject([
        'Bucket' => $bucket,
        'Key' => $destKey,
        'Body' => $body,
        'ACL' => 'private',
        'ContentType' => $contentType,
        'Metadata' => [
            'origin' => $sourceEncrypted,
            'jobname' => $jobName,
            'service' => 'amazon-transcribe',
        ]
    ]);

    $metadatosArray = array_merge([
        'tipo' => $contentType,
        'servicio' => 'Amazon Transcribe',
        'jobName' => $jobName,
        'languageCode' => $languageCode,
        'origen_nombre' => $sourceNombre,
        'origen_encriptado' => $sourceEncrypted,
        'destino_nombre' => $destNombre,
        'destino_encriptado' => $destKey,
        'ruta' => $ruta,
        'tamano_bytes' => $tamanoBytes,
        'status' => $status,
        'fecha' => date('Y-m-d'),
        'hora' => date('H:i:s'),
    ], $extraMeta);

    $metadatosJSON = json_encode($metadatosArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($metadatosJSON === false) {
        throw new Exception('Error al generar JSON de metadatos para ' . $destKey);
    }

    $dbStatus = tx_upsert_generated_file($db, $userId, $destNombre, $destKey, $tamanoBytes, $metadatosJSON, $ruta);

    return [
        'nombre' => $destNombre,
        'key' => $destKey,
        'ruta' => $ruta,
        'tamano' => $tamanoBytes,
        'db_status' => $dbStatus,
    ];
}

try {
    if (empty($_SESSION['usuario'])) {
        throw new Exception('Sesión inválida.');
    }
    if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
        throw new Exception('Conexión a BD no inicializada.');
    }

    $jobName = tx_get_param('jobName');
    $archivoInput = tx_get_param('archivo');
    if ($jobName === '') {
        throw new Exception('Falta jobName.');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception('No se encontró user_id en la sesión.');
    }

    $transcribe = new Aws\TranscribeService\TranscribeServiceClient([
        'version' => 'latest',
        'region' => Config::REGION,
        'credentials' => [
            'key' => Config::ACCESS_KEY,
            'secret' => Config::SECRET_KEY,
        ],
    ]);

    $result = $transcribe->getTranscriptionJob([
        'TranscriptionJobName' => $jobName,
    ]);

    $job = $result['TranscriptionJob'] ?? [];
    if (empty($job)) {
        throw new Exception('Job no encontrado.');
    }

    $bucket = Config::BUCKET;
    $mediaFileUri = (string)($job['Media']['MediaFileUri'] ?? '');
    $archivoEncriptadoDesdeJob = tx_s3_uri_to_key($mediaFileUri, $bucket);

    $rutaActual = tx_normalize_path((string)($_SESSION['ruta_actual'] ?? ''));
    $sourceLookup = $archivoInput !== '' ? $archivoInput : $archivoEncriptadoDesdeJob;
    $sourceRow = tx_find_file_row($db_connection, $userId, $sourceLookup, $rutaActual);

    if ($archivoEncriptadoDesdeJob !== '' && tx_normalize_path((string)$sourceRow['Encriptado']) !== $archivoEncriptadoDesdeJob) {
        $sourceRow = tx_find_file_row($db_connection, $userId, $archivoEncriptadoDesdeJob, $rutaActual);
    }

    $status = $job['TranscriptionJobStatus'] ?? 'UNKNOWN';
    $response = [
        'ok' => true,
        'jobName' => $jobName,
        'status' => $status,
        'message' => $job['FailureReason'] ?? null,
        'languageCode' => $job['LanguageCode'] ?? null,
        'identifiedLanguageScore' => $job['IdentifiedLanguageScore'] ?? null,
        'transcriptUri' => $job['Transcript']['TranscriptFileUri'] ?? null,
        'redactedTranscriptUri' => $job['Transcript']['RedactedTranscriptFileUri'] ?? null,
        'subtitleUris' => $job['Subtitles']['SubtitleFileUris'] ?? [],
        'archivoKey' => $sourceRow['Encriptado'],
        'archivoNombre' => $sourceRow['Nombre'],
        'ruta' => $sourceRow['Ruta'],
    ];

    if ($status === 'FAILED' || $status !== 'COMPLETED') {
        tx_json_response($response);
    }

    $s3 = Config::getS3();
    $manager = new S3Manager();
    $targetBucket = $manager->getBucket();
    $languageCode = $job['LanguageCode'] ?? '';
    $guardados = [];

    $transcriptUri = $job['Transcript']['TranscriptFileUri'] ?? '';
    if ($transcriptUri === '') {
        throw new Exception('Amazon no devolvió TranscriptFileUri.');
    }

    $jsonBody = tx_fetch_remote_binary_smart($s3, $targetBucket, $transcriptUri);
    $jsonDecoded = json_decode($jsonBody, true);
    if (!is_array($jsonDecoded)) {
        throw new Exception('El resultado descargado no es un JSON válido de Amazon Transcribe.');
    }
    $texto = '';
    if (is_array($jsonDecoded) && !empty($jsonDecoded['results']['transcripts'][0]['transcript'])) {
        $texto = (string)$jsonDecoded['results']['transcripts'][0]['transcript'];
    }

    $encryptedBase = pathinfo((string)$sourceRow['Encriptado'], PATHINFO_FILENAME);
    $visibleBase = pathinfo((string)$sourceRow['Nombre'], PATHINFO_FILENAME);
    $jsonDestKey = (string)$sourceRow['Ruta'] . $encryptedBase . '.json';
    $jsonNombre = $visibleBase . '.json';

    $s3->putObject([
        'Bucket' => $targetBucket,
        'Key' => $jsonDestKey,
        'Body' => $jsonBody,
        'ACL' => 'private',
        'ContentType' => 'application/json',
        'Metadata' => [
            'origin' => (string)$sourceRow['Encriptado'],
            'jobname' => $jobName,
            'service' => 'amazon-transcribe',
        ]
    ]);

    $tamanoJson = strlen($jsonBody);
    $jsonMeta = json_encode([
        'tipo' => 'application/json',
        'servicio' => 'Amazon Transcribe',
        'jobName' => $jobName,
        'languageCode' => $languageCode,
        'origen_nombre' => (string)$sourceRow['Nombre'],
        'origen_encriptado' => (string)$sourceRow['Encriptado'],
        'destino_nombre' => $jsonNombre,
        'destino_encriptado' => $jsonDestKey,
        'ruta' => (string)$sourceRow['Ruta'],
        'tamano_bytes' => $tamanoJson,
        'status' => $status,
        'transcript_uri' => $transcriptUri,
        'fecha' => date('Y-m-d'),
        'hora' => date('H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonMeta === false) {
        throw new Exception('Error al generar JSON de metadatos para ' . $jsonDestKey);
    }

    $jsonDbStatus = tx_upsert_generated_file($db_connection, $userId, $jsonNombre, $jsonDestKey, $tamanoJson, $jsonMeta, (string)$sourceRow['Ruta']);
    $guardados['json'] = [
        'nombre' => $jsonNombre,
        'key' => $jsonDestKey,
        'ruta' => (string)$sourceRow['Ruta'],
        'tamano' => $tamanoJson,
        'db_status' => $jsonDbStatus,
    ];

    $subtitleUris = $job['Subtitles']['SubtitleFileUris'] ?? [];
    if (is_array($subtitleUris) && !empty($subtitleUris)) {
        foreach ($subtitleUris as $subtitleUri) {
            $path = parse_url($subtitleUri, PHP_URL_PATH);
            $ext = strtolower((string)pathinfo((string)$path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['srt', 'vtt'], true)) {
                continue;
            }
            $contentType = ($ext === 'srt') ? 'application/x-subrip' : 'text/vtt';
            $guardados[$ext] = tx_store_generated_variant(
                $s3,
                $db_connection,
                $userId,
                $targetBucket,
                $sourceRow,
                $jobName,
                $languageCode,
                $status,
                $subtitleUri,
                $ext,
                $contentType,
                [
                    'subtitle_uri' => $subtitleUri,
                    'subtitle_format' => $ext,
                ]
            );
        }
    }

    $response['texto'] = $texto;
    $response['json_s3_key'] = $jsonDestKey;
    $response['json_nombre'] = $jsonNombre;
    $response['guardados'] = $guardados;
    tx_json_response($response);

} catch (\Aws\Exception\AwsException $e) {
    tx_json_response([
        'ok' => false,
        'error' => $e->getAwsErrorMessage() ?: $e->getMessage(),
    ], 400);
} catch (\Throwable $e) {
    tx_json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);
}
