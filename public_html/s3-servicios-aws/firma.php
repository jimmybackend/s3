<?php 
require_once __DIR__ . '/app_bootstrap.php';

$s3     = Config::getS3();
$bucket = Config::BUCKET;

// Parámetros
$nombreOriginal = $_GET['nombre'] ?? '';
$carpeta        = $_GET['carpeta'] ?? Config::RUTA_COMPARTIDA;

if (!$nombreOriginal) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta el nombre del archivo']);
    exit;
}

// Preparar nombres y ruta
$extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
$nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($extension ? ".$extension" : '');
$key = rtrim($carpeta, '/') . '/' . $nombreEncriptado;

// Generar URL firmada
try {
    $cmd = $s3->getCommand('PutObject', [
        'Bucket' => $bucket,
        'Key'    => $key,
        'ACL'    => 'private'
    ]);

    $request = $s3->createPresignedRequest($cmd, '+1 hour');
    $url     = (string) $request->getUri();

    // Crear metadatos para registrar en base de datos
    $metadatos = json_encode([
        'ip_origen'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido',
        'host_remoto'    => gethostbyaddr($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        'idioma'         => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'desconocido',
        'referer'        => $_SERVER['HTTP_REFERER'] ?? 'ninguno',
        'conexion'       => $_SERVER['HTTP_CONNECTION'] ?? 'desconocido',
        'puerto_remoto'  => $_SERVER['REMOTE_PORT'] ?? '0',
        'fecha_servidor' => date('Y-m-d'),
        'hora_servidor'  => date('H:i:s'),
        'zona_horaria'   => date_default_timezone_get(),
        'usuario_envio'  => $_SESSION['username'] ?? 'publico'
    ]);

    $userId = $_SESSION['user_id'] ?? 0; // 0 si no hay sesión válida

    // Datos adicionales según estructura de FileS3
    $tamanoArchivo   = 0; // Por ahora 0, se actualizará al completar la subida
    $ruta            = rtrim($carpeta, '/') . '/';
    $found           = 1;
    $accessType      = 'normal';
    $passwordHash    = null;
    $secureHint      = null;
    $secureUpdatedAt = null;
    $fechaActual     = date('Y-m-d H:i:s');

    // Preparar statement con todos los campos requeridos
    $stmt = $db_connection->prepare("
        INSERT INTO FileS3 
            (Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt, Fecha, user_id_)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('Error en la preparación del statement: ' . $db_connection->error);
    }

    $stmt->bind_param(
        'ssississsssi',
        $nombreOriginal,
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
        throw new Exception('Error al insertar archivo: ' . $stmt->error);
    }

    $insertId = $stmt->insert_id;
    $stmt->close();

    // Respuesta JSON con URL firmada y datos básicos
    echo json_encode([
        'status'     => 'success',
        'url'        => $url,
        'file_id'    => $insertId,
        'encriptado' => $nombreEncriptado,
        'ruta'       => $ruta
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>