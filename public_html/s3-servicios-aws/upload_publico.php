<?php
require 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['estado' => 'error', 'mensaje' => 'Método no permitido']);
    exit;
}

$s3     = Config::getS3();
$bucket = Config::BUCKET;
$prefix = $_GET['prefix'] ?? Config::RUTA_COMPARTIDA;

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['estado' => 'error', 'mensaje' => 'Archivo no recibido.']);
    exit;
}

$file     = $_FILES['file'];
$tmpPath  = $file['tmp_name'];
$nameOrig = basename($file['name']);

if (!file_exists($tmpPath) || filesize($tmpPath) === 0) {
    http_response_code(400);
    echo json_encode(['estado' => 'error', 'mensaje' => 'El archivo temporal no existe o está vacío.']);
    exit;
}

if (!is_uploaded_file($tmpPath)) {
    http_response_code(500);
    echo json_encode(['estado' => 'error', 'mensaje' => 'El archivo no fue subido correctamente.']);
    exit;
}

// Generar nombre encriptado único
$extension = pathinfo($nameOrig, PATHINFO_EXTENSION);
$nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($extension ? ".$extension" : '');
$key = rtrim($prefix, '/') . '/' . $nombreEncriptado;

// Función para obtener metadatos seguros
function safeMeta($key, $default = 'desconocido') {
    $value = $_SERVER[$key] ?? $default;
    return substr($value, 0, 255);
}

// Crear metadatos para la base de datos
$metadatos = json_encode([
    'ip_origen'      => safeMeta('REMOTE_ADDR'),
    'user_agent'     => safeMeta('HTTP_USER_AGENT'),
    'host_remoto'    => gethostbyaddr($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
    'idioma'         => safeMeta('HTTP_ACCEPT_LANGUAGE'),
    'referer'        => safeMeta('HTTP_REFERER', 'ninguno'),
    'conexion'       => safeMeta('HTTP_CONNECTION'),
    'puerto_remoto'  => safeMeta('REMOTE_PORT'),
    'fecha'          => date('Y-m-d'),
    'hora'           => date('H:i:s'),
    'tamano_kb'      => round(filesize($tmpPath) / 1024, 2),
    'hash_sha256'    => hash_file('sha256', $tmpPath),
    'ruta_s3'        => $key
]);

try {
    // 📤 Subida al bucket S3
    $s3->putObject([
        'Bucket'     => $bucket,
        'Key'        => $key,
        'SourceFile' => $tmpPath,
        'ACL'        => 'private',
        'ContentType'=> mime_content_type($tmpPath)
    ]);

    // 🧩 Registro completo en la tabla FileS3
    $tamanoArchivo   = filesize($tmpPath);
    $userId          = $_SESSION['user_id'] ?? 0;
    $ruta            = rtrim($prefix, '/') . '/';
    $found           = 1;
    $accessType      = 'normal';
    $passwordHash    = null;
    $secureHint      = null;
    $secureUpdatedAt = null;
    $fechaActual     = date('Y-m-d H:i:s');

    $sql = "
        INSERT INTO FileS3 
            (Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt, Fecha, user_id_)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $db_connection->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error preparando SQL: ' . $db_connection->error);
    }

    $stmt->bind_param(
        'ssississsssi',
        $nameOrig,
        $nombreEncriptado,
        $tamanoArchivo,
        $metadatos,
        $ruta,
        $found,
        $accessType,
        $passwordHash,
        $secureHint,
        $secureUpdatedAt,
        $fechaActual,
        $userId
    );

    if (!$stmt->execute()) {
        throw new Exception('Error al insertar en FileS3: ' . $stmt->error);
    }

    $fileId = $stmt->insert_id;
    $stmt->close();

    echo json_encode([
        'estado'             => 'ok',
        'mensaje'            => 'Archivo subido y registrado correctamente.',
        'nombre_original'    => $nameOrig,
        'nombre_encriptado'  => $nombreEncriptado,
        'ruta_s3'            => $key,
        'id_registro'        => $fileId
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['estado' => 'error', 'mensaje' => $e->getMessage()]);
}
