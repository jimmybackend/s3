<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    exit(json_encode(['estado' => 'error', 'mensaje' => 'Acceso denegado']));
}

require '/../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\Exception\AwsException;

$s3     = Config::getS3();
$bucket = Config::BUCKET;

// 🔐 Usar la ruta de la sesión si existe
$rutaBase = $_SESSION['ruta_actual'] ?? Config::RUTA_RAIZ;
$rutaBase = rtrim($rutaBase, '/') . '/';

$archivos       = $_FILES['file'];
$rutasCompletas = $_POST['ruta_completa'] ?? [];

$respuestas = [];

if (!empty($archivos['tmp_name'])) {
    // Normalizar si se subió solo un archivo
    if (!is_array($archivos['tmp_name'])) {
        $archivos = [
            'name'     => [$archivos['name']],
            'type'     => [$archivos['type']],
            'tmp_name' => [$archivos['tmp_name']],
            'error'    => [$archivos['error']],
            'size'     => [$archivos['size']]
        ];
    }

    for ($i = 0; $i < count($archivos['name']); $i++) {
        if ($archivos['error'][$i] !== UPLOAD_ERR_OK) continue;

        $tmpFile        = $archivos['tmp_name'][$i];
        $nombreOriginal = $archivos['name'][$i];
        $rutaRelativa   = $rutasCompletas[$i] ?? $nombreOriginal;
        $rutaRelativa   = ltrim($rutaRelativa, '/');

        // 🛡️ Generar nombre encriptado
        $extension     = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $nombreHash    = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . ($extension ? ".$extension" : '');
        $keyFinal      = $rutaBase . $nombreHash;

        // 📦 Metadatos locales
        $metadatosArray = [
            'tipo'        => mime_content_type($tmpFile),
            'tamano_kb'   => round(filesize($tmpFile) / 1024, 2),
            'hash_sha256' => hash_file('sha256', $tmpFile),
            'subido_por'  => $_SESSION['usuario'],
            'ip_origen'   => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'fecha'       => date('Y-m-d'),
            'hora'        => date('H:i:s'),
            'navegador'   => $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido'
        ];

        try {
            // 📤 Subir a S3
            $s3->putObject([
                'Bucket'     => $bucket,
                'Key'        => $keyFinal,
                'SourceFile' => $tmpFile,
                'ACL'        => 'private',
                'Metadata'   => $metadatosArray
            ]);

            // 🧩 Registrar en base de datos
            $tamanoArchivo   = filesize($tmpFile);
            $ruta            = $rutaBase; // ruta completa desde la sesión
            $found           = 1;
            $accessType      = 'normal';
            $passwordHash    = null;
            $secureHint      = null;
            $secureUpdatedAt = null;
            $fechaActual     = date('Y-m-d H:i:s');
            $userId          = $_SESSION['user_id'] ?? 0;
            $metadatosJSON   = json_encode($metadatosArray);

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
                $nombreOriginal,
                $nombreHash,
                $tamanoArchivo,
                $metadatosJSON,
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

            $respuestas[] = [
                'archivo' => $keyFinal,
                'estado'  => 'ok',
                'file_id' => $stmt->insert_id
            ];

            $stmt->close();

        } catch (AwsException $e) {
            $respuestas[] = [
                'archivo'  => $keyFinal,
                'estado'   => 'error',
                'mensaje'  => $e->getAwsErrorMessage()
            ];
        } catch (Exception $e) {
            $respuestas[] = [
                'archivo'  => $keyFinal,
                'estado'   => 'error',
                'mensaje'  => $e->getMessage()
            ];
        }
    }

    http_response_code(200);
    echo json_encode(['estado' => 'ok', 'resultados' => $respuestas]);
} else {
    http_response_code(400);
    echo json_encode(['estado' => 'error', 'mensaje' => 'No se recibieron archivos válidos.']);
}
