<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

require 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\S3\S3Client;

//
// Intentar incluir db.php que define $db_connection (mysqli)
//
$db_included = false;
$paths = [__DIR__ . '/db.php', __DIR__ . '/../db.php'];
foreach ($paths as $p) {
    if (is_file($p)) {
        include_once $p;
        $db_included = true;
        break;
    }
}
// $db_connection (mysqli) debería existir si el include fue correcto.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['estado' => 'error', 'mensaje' => 'Método no permitido']);
    exit;
}

$termino = trim($_POST['termino'] ?? '');
if ($termino === '') {
    echo json_encode(['estado' => 'error', 'mensaje' => 'Término vacío']);
    exit;
}

// Convertir comodines a regex: *.pdf, archivo?.txt, etc.
$patron = str_replace(['.', '*', '?'], ['\.', '.*', '.'], $termino);
$regex  = '/^' . $patron . '$/i';

// Config S3
$s3     = Config::getS3();
$bucket = Config::BUCKET;
$prefix = Config::RUTA_RAIZ;

$resultados = [];

try {
    // Preparar statements de DB si hay conexión
    $stmtSel = null;
    $stmtUpd = null;
    $db_ok   = (isset($db_connection) && $db_connection instanceof mysqli);

    if ($db_ok) {
        // Seleccionar nombre y ruta por Encriptado (basename)
        $stmtSel = $db_connection->prepare("SELECT `Nombre`, `Ruta` FROM `FileS3` WHERE `Encriptado` = ? LIMIT 1");
        // Actualizar Ruta si está vacía
        $stmtUpd = $db_connection->prepare("UPDATE `FileS3` SET `Ruta` = ? WHERE `Encriptado` = ? AND (`Ruta` IS NULL OR `Ruta` = '')");
    }

    $continuationToken = null;
    do {
        $params = [
            'Bucket' => $bucket,
            'Prefix' => $prefix,
        ];
        if ($continuationToken) {
            $params['ContinuationToken'] = $continuationToken;
        }

        $resultado = $s3->listObjectsV2($params);

        foreach ($resultado['Contents'] ?? [] as $obj) {
            $key = $obj['Key'];
            if (substr($key, -1) === '/') continue; // Omitir "carpetas"

            $basename = basename($key);
            if (!preg_match($regex, $basename)) continue;

            $pesoKB = round(((float)$obj['Size']) / 1024, 2);

            // Ruta contenedora estilo "carpeta/subcarpeta/"
            $ruta = dirname($key);
            if ($ruta === '.' || $ruta === '') {
                $ruta = '';
            } else {
                $ruta .= '/';
            }

            // Defaults (si no hay DB)
            $nombreReal = $basename;
            $rutaDB     = null;

            // Consultar nombre real y ruta en DB por Encriptado = basename
            if ($db_ok && $stmtSel) {
                $stmtSel->bind_param('s', $basename);
                if ($stmtSel->execute()) {
                    $res = $stmtSel->get_result();
                    if ($res && ($fila = $res->fetch_assoc())) {
                        if (!empty($fila['Nombre'])) {
                            $nombreReal = $fila['Nombre'];
                        }
                        $rutaDB = $fila['Ruta'] ?? null;
                    }
                    if ($res) $res->free();
                }

                // Si en DB la Ruta está vacía, la actualizamos con la ruta detectada
                if (($rutaDB === null || $rutaDB === '') && $ruta !== '' && $stmtUpd) {
                    $stmtUpd->bind_param('ss', $ruta, $basename);
                    $stmtUpd->execute(); // no importa el resultado; best-effort
                    // No releemos; usamos la $ruta para responder
                }
            }

            $resultados[] = [
                'key'         => $key,
                'nombre'      => $basename,           // encriptado (fallback)
                'nombre_real' => $nombreReal,         // legible desde DB si existe
                'ruta'        => $ruta,               // ruta encontrada en S3
                'tamano_kb'   => $pesoKB,
                'fecha'       => $obj['LastModified'] instanceof \DateTimeInterface
                                   ? $obj['LastModified']->format('Y-m-d H:i:s')
                                   : '',
            ];
        }

        $continuationToken = $resultado['NextContinuationToken'] ?? null;

    } while ($continuationToken);

    echo json_encode([
        'estado'     => 'ok',
        'resultados' => $resultados,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'estado'  => 'error',
        'mensaje' => 'Error: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
