<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    } else {
        http_response_code(403);
        exit('Acceso denegado');
    }
}

require 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\Exception\AwsException;

$s3     = Config::getS3();
$bucket = Config::BUCKET;

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// --- Datos recibidos ---
$archivo = isset($_POST['archivo']) ? trim($_POST['archivo']) : '';
$ruta    = isset($_POST['ruta']) ? trim($_POST['ruta']) : '';

if ($archivo === '') {
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Archivo no especificado']);
        exit;
    } else {
        exit('Archivo no especificado.');
    }
}

// --- Determinar Key final para S3 ---
if (strpos($archivo, '/') !== false) {
    // Ya contiene ruta completa
    $fullKey = $archivo;
} else {
    // Concatenar la ruta actual si viene
    $fullKey = rtrim($ruta, '/');
    if ($fullKey !== '') {
        $fullKey .= '/';
    }
    $fullKey .= $archivo;
}

// --- Nombre base del archivo para eliminar en BD ---
$nombreEncriptado = basename($archivo);

try {
    // 1️⃣ Eliminar de S3
    $s3->deleteObject([
        'Bucket' => $bucket,
        'Key'    => $fullKey
    ]);

    // 2️⃣ Eliminar de base de datos (si existe la conexión)
    if (isset($db_connection) && $db_connection instanceof mysqli) {
        if ($stmt = $db_connection->prepare("DELETE FROM FileS3 WHERE Encriptado = ? LIMIT 1")) {
            $stmt->bind_param("s", $nombreEncriptado);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 3️⃣ Respuesta
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => true,
            'deleted' => $fullKey
        ]);
        exit;
    } else {
        header('Location: s3.php');
        exit;
    }

} catch (AwsException $e) {
    $msg = $e->getAwsErrorMessage() ?: $e->getMessage();
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    } else {
        exit('Error AWS: ' . htmlspecialchars($msg));
    }
} catch (Throwable $e) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    } else {
        exit('Error al eliminar: ' . htmlspecialchars($e->getMessage()));
    }
}
