<?php
require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

function tx_json_error($message, array $extra = []) {
    http_response_code(400);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tx_post_bool($key) {
    if (!isset($_POST[$key])) {
        return false;
    }
    $value = $_POST[$key];
    if (is_bool($value)) {
        return $value;
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
}

function tx_post_array($key) {
    if (!isset($_POST[$key])) {
        return [];
    }
    $value = $_POST[$key];
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value), static function ($v) {
            return $v !== '';
        }));
    }
    $value = trim((string)$value);
    if ($value === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $value)), static function ($v) {
        return $v !== '';
    }));
}

function tx_sanitize_job_name($name) {
    $name = preg_replace('/\.[^.]+$/', '', (string)$name);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $name = trim((string)$name, '.-_');
    if ($name === '') {
        $name = 'transcripcion';
    }
    return substr($name, 0, 200);
}

function tx_build_s3_uri($bucket, $key) {
    return 's3://' . $bucket . '/' . ltrim($key, '/');
}

function tx_normalize_path($path) {
    $path = str_replace('\\', '/', (string)$path);
    $path = preg_replace('#/+#', '/', $path);
    return ltrim($path, '/');
}

function tx_dirname_key($key) {
    $key = tx_normalize_path($key);
    $pos = strrpos($key, '/');
    if ($pos === false) {
        return '';
    }
    return substr($key, 0, $pos + 1);
}

function tx_find_source_file(mysqli $db, int $userId, string $archivoInput, string $rutaActual = '') {
    $archivoInput = tx_normalize_path($archivoInput);
    $rutaActual = tx_normalize_path($rutaActual);

    if ($archivoInput === '') {
        throw new Exception('Falta parámetro archivo.');
    }

    $nombre = basename($archivoInput);
    $rutaInput = tx_dirname_key($archivoInput);
    $rutaLookup = $rutaInput !== '' ? $rutaInput : $rutaActual;

    $candidates = [];

    $queries = [
        [
            'sql' => "SELECT Nombre, Encriptado, Ruta, Found, AccessType FROM FileS3 WHERE user_id_ = ? AND Encriptado = ? LIMIT 1",
            'types' => 'is',
            'params' => [$userId, $archivoInput],
        ],
        [
            'sql' => "SELECT Nombre, Encriptado, Ruta, Found, AccessType FROM FileS3 WHERE user_id_ = ? AND Nombre = ? AND Ruta = ? LIMIT 1",
            'types' => 'iss',
            'params' => [$userId, $nombre, $rutaLookup],
        ],
        [
            'sql' => "SELECT Nombre, Encriptado, Ruta, Found, AccessType FROM FileS3 WHERE user_id_ = ? AND Nombre = ? ORDER BY id_ DESC LIMIT 1",
            'types' => 'is',
            'params' => [$userId, $nombre],
        ],
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
            return [
                'Nombre' => (string)$row['Nombre'],
                'Encriptado' => tx_normalize_path((string)$row['Encriptado']),
                'Ruta' => tx_normalize_path((string)$row['Ruta']),
                'Found' => (int)$row['Found'],
                'AccessType' => (string)$row['AccessType'],
            ];
        }
    }

    throw new Exception('No se encontró el archivo en FileS3 para transcribir.');
}

try {
    if (empty($_SESSION['usuario'])) {
        throw new Exception('Sesión inválida.');
    }
    if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
        throw new Exception('Conexión a BD no inicializada.');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception('No se encontró user_id en la sesión.');
    }

    $archivoInput = trim((string)($_POST['archivo'] ?? ''));
    $rutaActual = tx_normalize_path((string)($_SESSION['ruta_actual'] ?? ''));
    $fileRow = tx_find_source_file($db_connection, $userId, $archivoInput, $rutaActual);

    $archivoKey = $fileRow['Encriptado'];
    $nombreVisible = $fileRow['Nombre'];
    $rutaVisible = $fileRow['Ruta'];

    $ext = strtolower((string)pathinfo($archivoKey, PATHINFO_EXTENSION));
    $formatosSoportados = ['mp3', 'mp4', 'wav', 'flac', 'ogg', 'amr', 'webm', 'm4a', 'opus'];
    if (!in_array($ext, $formatosSoportados, true)) {
        tx_json_error('Formato no soportado para transcripción', ['ext' => $ext]);
    }

    $mediaFormat = ($ext === 'opus') ? 'ogg' : $ext;
    $bucket = Config::BUCKET;
    $inputS3Uri = tx_build_s3_uri($bucket, $archivoKey);

    $nombreBaseVisible = pathinfo($nombreVisible, PATHINFO_FILENAME);
    $jobNameInput = trim((string)($_POST['jobName'] ?? ''));
    if ($jobNameInput === '') {
        $jobNameInput = $nombreBaseVisible;
    }
    $jobName = tx_sanitize_job_name($jobNameInput);

    $languageMode = trim((string)($_POST['languageMode'] ?? 'specific'));
    if ($languageMode === '') {
        $languageMode = 'specific';
    }
    $languageCode = trim((string)($_POST['languageCode'] ?? 'es-ES'));
    if ($languageCode === '') {
        $languageCode = 'es-ES';
    }
    $languageOptions = tx_post_array('languageOptions');
    $modelType = trim((string)($_POST['modelType'] ?? 'general'));
    $customLanguageModelName = trim((string)($_POST['customLanguageModelName'] ?? ''));

    $outputBucketName = $bucket;
    $outputKey = $rutaVisible;

    $subtitleFormats = tx_post_array('subtitleFormats');
    $subtitleFormats = array_values(array_intersect($subtitleFormats, ['vtt', 'srt']));

    $enableChannelIdentification = tx_post_bool('enableChannelIdentification');
    $enableShowSpeakerLabels = tx_post_bool('enableShowSpeakerLabels');
    $maxSpeakerLabels = (int)($_POST['maxSpeakerLabels'] ?? 10);
    if ($maxSpeakerLabels < 2) {
        $maxSpeakerLabels = 2;
    }
    if ($maxSpeakerLabels > 30) {
        $maxSpeakerLabels = 30;
    }

    $showAlternatives = tx_post_bool('showAlternatives');
    $maxAlternatives = (int)($_POST['maxAlternatives'] ?? 2);
    if ($maxAlternatives < 2) {
        $maxAlternatives = 2;
    }
    if ($maxAlternatives > 10) {
        $maxAlternatives = 10;
    }

    $enableContentRedaction = tx_post_bool('enableContentRedaction');
    $piiEntityTypes = tx_post_array('piiEntityTypes');
    $vocabularyName = trim((string)($_POST['vocabularyName'] ?? ''));
    $vocabularyFilterName = trim((string)($_POST['vocabularyFilterName'] ?? ''));
    $vocabularyFilterMethod = trim((string)($_POST['vocabularyFilterMethod'] ?? 'remove'));
    $toxicityDetection = tx_post_bool('toxicityDetection');
    $toxicityCategories = tx_post_array('toxicityCategories');
    $enablePhi = tx_post_bool('enablePhi');

    if ($enablePhi) {
        tx_json_error('PHI requiere Amazon Transcribe Medical y no está disponible en este flujo estándar.');
    }
    if ($enableChannelIdentification && $enableShowSpeakerLabels) {
        tx_json_error('No puedes usar identificación de canales y partición de voces al mismo tiempo.');
    }
    if ($enableContentRedaction) {
        if (!in_array($languageCode, ['en-US', 'es-US'], true)) {
            tx_json_error('La redacción PII en trabajos estándar requiere en-US o es-US.');
        }
        if ($languageMode !== 'specific') {
            tx_json_error('La redacción PII requiere Idioma específico.');
        }
    }

    $params = [
        'TranscriptionJobName' => $jobName,
        'Media' => ['MediaFileUri' => $inputS3Uri],
        'MediaFormat' => $mediaFormat,
        'OutputBucketName' => $outputBucketName,
    ];
    if ($outputKey !== '') {
        $params['OutputKey'] = $outputKey;
    }

    switch ($languageMode) {
        case 'auto':
            $params['IdentifyLanguage'] = true;
            if (!empty($languageOptions)) {
                $params['LanguageOptions'] = $languageOptions;
            }
            break;
        case 'auto_multi':
            $params['IdentifyMultipleLanguages'] = true;
            if (!empty($languageOptions)) {
                $params['LanguageOptions'] = $languageOptions;
            }
            break;
        case 'specific':
        default:
            $params['LanguageCode'] = $languageCode;
            break;
    }

    $settings = [];
    if ($enableChannelIdentification) {
        $settings['ChannelIdentification'] = true;
    }
    if ($enableShowSpeakerLabels) {
        $settings['ShowSpeakerLabels'] = true;
        $settings['MaxSpeakerLabels'] = $maxSpeakerLabels;
    }
    if ($showAlternatives) {
        $settings['ShowAlternatives'] = true;
        $settings['MaxAlternatives'] = $maxAlternatives;
    }
    if ($vocabularyName !== '') {
        $settings['VocabularyName'] = $vocabularyName;
    }
    if ($vocabularyFilterName !== '') {
        $settings['VocabularyFilterName'] = $vocabularyFilterName;
        $settings['VocabularyFilterMethod'] = in_array($vocabularyFilterMethod, ['remove', 'mask', 'tag'], true)
            ? $vocabularyFilterMethod
            : 'remove';
    }
    if (!empty($settings)) {
        $params['Settings'] = $settings;
    }

    if ($modelType === 'custom' && $customLanguageModelName !== '') {
        $params['ModelSettings'] = [
            'LanguageModelName' => $customLanguageModelName,
        ];
    }

    if (!empty($subtitleFormats)) {
        $params['Subtitles'] = [
            'Formats' => $subtitleFormats,
            'OutputStartIndex' => 1,
        ];
    }

    if ($enableContentRedaction) {
        $params['ContentRedaction'] = [
            'RedactionType' => 'PII',
            'RedactionOutput' => 'redacted_and_unredacted',
        ];
        if (!empty($piiEntityTypes)) {
            $params['ContentRedaction']['PiiEntityTypes'] = $piiEntityTypes;
        }
    }

    if ($toxicityDetection) {
        $toxicityItem = [];
        if (!empty($toxicityCategories)) {
            $toxicityItem['ToxicityCategories'] = $toxicityCategories;
        }
        $params['ToxicityDetection'] = [$toxicityItem];
    }

    $transcribe = new Aws\TranscribeService\TranscribeServiceClient([
        'version'     => 'latest',
        'region'      => Config::REGION,
        'credentials' => [
            'key'    => Config::ACCESS_KEY,
            'secret' => Config::SECRET_KEY,
        ],
    ]);

    try {
        $transcribe->getTranscriptionJob(['TranscriptionJobName' => $jobName]);
        $jobName .= '_' . date('YmdHis');
        $params['TranscriptionJobName'] = $jobName;
    } catch (\Aws\Exception\AwsException $e) {
    }

    $res = $transcribe->startTranscriptionJob($params);
    $job = $res['TranscriptionJob'] ?? [];

    echo json_encode([
        'ok' => true,
        'jobName' => $jobName,
        'status' => $job['TranscriptionJobStatus'] ?? null,
        'inputS3Uri' => $inputS3Uri,
        'archivo' => $archivoInput,
        'archivoVisible' => $nombreVisible,
        'archivoEncriptado' => $archivoKey,
        'rutaDestino' => $rutaVisible,
        'nombreBase' => $nombreBaseVisible,
        'subtitleFormats' => $subtitleFormats,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Aws\Exception\AwsException $e) {
    tx_json_error($e->getAwsErrorMessage() ?: $e->getMessage());
} catch (Throwable $e) {
    tx_json_error($e->getMessage());
}
