<?php
session_start();
// descargar_zip.php — descarga ZIP con nombres “visibles” desde la BD


if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    exit('Acceso denegado');
}

require_once __DIR__ . '/app_bootstrap.php';

$s3     = Config::getS3();
$bucket = Config::BUCKET;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Método no permitido');
    }

    // 1) Obtener lista de archivos (keys S3) desde archivos_json o archivos[]
    $lista = [];
    if (isset($_POST['archivos_json'])) {
        $tmp = json_decode($_POST['archivos_json'], true);
        if (is_array($tmp)) $lista = $tmp;
    }
    if (!$lista && !empty($_POST['archivos']) && is_array($_POST['archivos'])) {
        $lista = $_POST['archivos'];
    }
    if (empty($lista)) {
        http_response_code(400);
        exit('No hay archivos seleccionados.');
    }

    // 2) Mapear nombres encriptados -> nombres visibles (BD)
    $encKeys = array_values(array_filter(array_map(function($k){
        return basename((string)$k);
    }, $lista)));

    $nombrePorEnc = [];
    if (isset($db_connection) && $db_connection instanceof mysqli && $encKeys) {
        // SELECT Encriptado, Nombre ...
        // Evitar bind variádico si tu PHP es viejo: haz batches
        for ($i = 0; $i < count($encKeys); $i += 200) {
            $chunk = array_slice($encKeys, $i, 200);
            $place = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('s', count($chunk));
            $stmt  = $db_connection->prepare("SELECT Encriptado, Nombre FROM FileS3 WHERE Encriptado IN ($place)");
            if ($stmt) {
                $stmt->bind_param($types, ...$chunk);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $enc = (string)$row['Encriptado'];
                    $nom = (string)$row['Nombre'];
                    $nombrePorEnc[$enc] = $nom;
                }
                $stmt->close();
            }
        }
    }

    // 3) Sanea nombres y preserva extensión
    $seenNames = [];
    $entryNameForKey = function(string $key) use (&$nombrePorEnc, &$seenNames) {
        $enc = basename($key);
        $ext = pathinfo($enc, PATHINFO_EXTENSION);
        $ext = $ext ? ('.' . strtolower($ext)) : '';

        // Nombre visible desde BD (si existe), si no, fallback al encriptado
        $base = $nombrePorEnc[$enc] ?? $enc;

        // Quitar extensiones duplicadas y sanitizar
        $baseNoExt = preg_replace('/\.[^.]+$/', '', $base); // quita última extensión si la trae
        $name = $baseNoExt . $ext;

        // Sanitizar para ZIP (sin slashes, sin control chars)
        $name = preg_replace('/[\/\\\\]/', '-', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $name = trim($name);
        if ($name === '') $name = $enc;

        // Evitar duplicados dentro del ZIP
        $final = $name;
        $i = 2;
        while (isset($seenNames[strtolower($final)])) {
            $final = $baseNoExt . " ($i)" . $ext;
            $final = preg_replace('/[\/\\\\]/', '-', $final);
            $i++;
        }
        $seenNames[strtolower($final)] = true;
        return $final;
    };

    // 4) Crear ZIP temporal y añadir archivos
    $zipName = 'archivos_' . date('Ymd_His') . '.zip';
    $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zip_', true) . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('No se pudo crear el ZIP.');
    }

    $tempFiles = [];
    foreach ($lista as $key) {
        $key = (string)$key;
        if ($key === '' || substr($key, -1) === '/') continue;

        $entry = $entryNameForKey($key);

        // Descarga a archivo temporal para evitar cargar todo en memoria
        $ext = pathinfo($key, PATHINFO_EXTENSION);
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('s3_', true) . ($ext ? ".$ext" : '');
        $s3->getObject([
            'Bucket' => $bucket,
            'Key'    => $key,
            'SaveAs' => $tmp
        ]);
        $tempFiles[] = $tmp;

        // Añade al ZIP con nombre amigable
        $zip->addFile($tmp, $entry);
    }

    $zip->close();

    // 5) Entregar ZIP
    // Cabeceras para descarga
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"');
    header('Content-Length: ' . filesize($zipPath));
    // (opcional) Cabezera auxiliar para JS
    header('X-Filename: ' . rawurlencode($zipName));

    // Vuelca el archivo
    $fp = fopen($zipPath, 'rb');
    if ($fp) {
        while (!feof($fp)) {
            echo fread($fp, 8192);
        }
        fclose($fp);
    }

    // Limpieza
    foreach ($tempFiles as $t) { @unlink($t); }
    @unlink($zipPath);

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}
