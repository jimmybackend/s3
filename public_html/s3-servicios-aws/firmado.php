<?php 
session_start();
require 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';


$s3     = Config::getS3();
$bucket = Config::BUCKET;

// -----------------------------
// Parámetros
// -----------------------------
$nombreOriginal = $_GET['nombre'] ?? '';

if (!$nombreOriginal) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta el nombre del archivo']);
    exit;
}

// Usar la ruta actual de la sesión en lugar de carpeta fija
$carpetaSesion = isset($_SESSION['ruta_actual']) ? trim($_SESSION['ruta_actual'], '/') : Config::RUTA_COMPARTIDA;

// -----------------------------
// Preparar nombres y ruta
// -----------------------------
$extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
$nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($extension ? ".$extension" : '');
$key = rtrim($carpetaSesion, '/') . '/' . $nombreEncriptado;

// -----------------------------
// Generar URL firmada
// -----------------------------
try {
    $cmd = $s3->getCommand('PutObject', [
        'Bucket' => $bucket,
        'Key'    => $key,
        'ACL'    => 'private'
    ]);

    $request = $s3->createPresignedRequest($cmd, '+1 hour');
    $url     = (string) $request->getUri();

    // -----------------------------
    // Crear metadatos solo en la base de datos
    // -----------------------------
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
        'usuario_envio'  => $_SESSION['usuario'] ?? 'publico'
    ]);

    $userId = $_SESSION['user_id'] ?? 0; // 0 si no hay sesión válida
    
    // Insertar en base de datos
    $stmt = $db_connection->prepare(
        "INSERT INTO FileS3 (Nombre, Encriptado, Metadatos, Tamano, user_id_) VALUES (?, ?, ?, ?, ?)"
    );
    $tamanoArchivo = 0; // Por ahora 0, actualizar luego si se conoce
    $stmt->bind_param("sssii", $nombreOriginal, $nombreEncriptado, $metadatos, $tamanoArchivo, $userId);
    $stmt->execute();


    echo json_encode([
        'url' => $url,
        'clave' => $key,
        'carpeta' => $carpetaSesion,
        'nombreOriginal' => $nombreOriginal,
        'nombreEncriptado' => $nombreEncriptado
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
