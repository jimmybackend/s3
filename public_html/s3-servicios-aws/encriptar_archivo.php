<?php
require 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    // Solo JSON POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['estado' => 'error', 'mensaje' => 'Método no permitido']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $keyOriginal = $data['key'] ?? '';
    if (!$keyOriginal) {
        http_response_code(400);
        echo json_encode(['estado' => 'error', 'mensaje' => 'Falta el nombre del archivo.']);
        exit;
    }

    $s3     = Config::getS3();
    $bucket = Config::BUCKET;

    // Descargar original (OJO: en archivos muy grandes es pesado; si quieras optimizar luego, hacemos copyObject)
    $obj = $s3->getObject([
        'Bucket' => $bucket,
        'Key'    => $keyOriginal
    ]);

    // Body puede ser un stream; fuerzo string
    $body = $obj['Body'];
    $contenido = is_string($body) ? $body : $body->getContents();

    // Generar nombre encriptado
    $extension = pathinfo($keyOriginal, PATHINFO_EXTENSION);
    $nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($extension ? ".$extension" : '');

    $dir = trim(dirname($keyOriginal), '/'); // dirname('.') si está en raíz
    $nuevaKey = ($dir ? $dir . '/' : '') . $nombreEncriptado;

    // Nombre "original" a guardar en DB
    $nombreOriginal = basename($keyOriginal);
    // Si ya tuvieras un mapping previo, lo respetamos (igual a tu código)
    $stmt = $db_connection->prepare("SELECT Nombre FROM FileS3 WHERE Encriptado = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $nombreOriginal);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($fila = $res->fetch_assoc()) {
            $nombreOriginal = $fila['Nombre'];
        }
        $stmt->close();
    }

    // Metadatos para tu DB
    $metadatos = json_encode([
        'origen_s3'   => $keyOriginal,
        'fecha'       => date('Y-m-d'),
        'hora'        => date('H:i:s'),
        'tamano_kb'   => round(strlen($contenido) / 1024, 2),
        'hash_sha256' => hash('sha256', $contenido),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Subir nuevo objeto
    $contentType = $obj['ContentType'] ?? 'application/octet-stream';
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $nuevaKey,
        'Body'        => $contenido,
        'ACL'         => 'private',
        'ContentType' => $contentType
    ]);

    // Borrar original
    $s3->deleteObject([
        'Bucket' => $bucket,
        'Key'    => $keyOriginal
    ]);

    // Guardar en DB (OJO: ahora con los **4** placeholders y tipos "sssi")
    $user_id_ = $_SESSION['user_id'] ?? 1; // ajusta si usas otro campo de sesión
    $stmt = $db_connection->prepare("INSERT INTO FileS3 (Nombre, Encriptado, Metadatos, user_id_) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception('DB prepare error: ' . $db_connection->error);
    }
    $stmt->bind_param("sssi", $nombreOriginal, $nombreEncriptado, $metadatos, $user_id_);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'estado'    => 'ok',
        'newKey'    => $nuevaKey,
        'encriptado'=> $nombreEncriptado
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['estado' => 'error', 'mensaje' => $e->getMessage()]);
}
