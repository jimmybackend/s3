<?php
// firmadowww.php — descarga desde URL (Drive/directo) y sube a S3 en la ruta de sesión.
// Compatible PHP 7.x, usa app_bootstrap.php (vendor + Config + DB fuera del webroot),
// soporta u64 (base64), maneja confirm de Drive, evita subir HTML,
// usa multipart streaming y registra en DB.

declare(strict_types=1);
session_start();
ignore_user_abort(true);
set_time_limit(0);
header('Content-Type: application/json; charset=UTF-8');
// error_reporting(E_ALL); // útil en logs, no imprime nada extra si no hay display_errors

// ====== Utilidades de respuesta JSON segura ======
function respond($code, $ok, array $payload)
{
    http_response_code((int)$code);
    $payload['ok'] = (bool)$ok;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== Cargar TODO desde bootstrap (vendor + Config + DB) ======
try {
    // Si este archivo está en s3v2/firmadowww.php:
    require_once __DIR__ . '/app_bootstrap.php';

    // Si lo mueves a s3v2/api/firmadowww.php, usa:
    // require_once __DIR__ . '/../app_bootstrap.php';
} catch (Exception $e) {
    respond(500, false, ['error' => 'No se pudo cargar app_bootstrap.php', 'details' => $e->getMessage()]);
} catch (Error $e) { // PHP 7.x
    respond(500, false, ['error' => 'No se pudo cargar app_bootstrap.php', 'details' => $e->getMessage()]);
}

// ====== Validar que Config y DB están disponibles ======
if (!class_exists('Config')) {
    respond(500, false, ['error' => 'Config no está disponible. Revisa app_bootstrap.php']);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    respond(500, false, ['error' => 'DB no disponible. Revisa app_bootstrap.php']);
}

// ====== Namespaces/clases AWS ======
use Aws\S3\S3Client;

// ===================== Helpers =====================
function driveIdFromUrl($url)
{
    $url = (string)$url;
    if (preg_match('#drive\.google\.com/file/d/([^/]+)#i', $url, $m)) return $m[1];
    if (preg_match('#drive\.google\.com/open\?id=([^&]+)#i', $url, $m)) return $m[1];
    if (preg_match('#(?:\?|&)id=([0-9A-Za-z_\-]+)#', $url, $m)) return $m[1];
    return null;
}

function driveNormalizeToUc($url)
{
    $id = driveIdFromUrl($url);
    return $id ? "https://drive.google.com/uc?export=download&id={$id}" : (string)$url;
}

function driveConfirmedUrlFromHtml($html, $id)
{
    $html = (string)$html;
    $id = (string)$id;

    if (preg_match('/confirm=([0-9A-Za-z\-_]+)/', $html, $m)) {
        $confirm = $m[1];
        return "https://drive.google.com/uc?export=download&confirm={$confirm}&id={$id}";
    }

    if (preg_match('#href="([^"]*?uc\?export=download[^"]*)"#i', $html, $m)) {
        $u = html_entity_decode($m[1], ENT_QUOTES);

        // PHP 7: reemplazo de str_starts_with
        if (strpos($u, 'https://') !== 0 && strpos($u, 'http://') !== 0) {
            $u = 'https://drive.google.com' . ((substr($u, 0, 1) === '/') ? '' : '/') . $u;
        }

        // PHP 7: reemplazo de str_contains
        if (strpos($u, 'id=') === false) {
            $u .= (strpos($u, '?') !== false ? '&' : '?') . 'id=' . $id;
        }

        return $u;
    }

    return null;
}

function isHtmlContentType($ct)
{
    return $ct && stripos((string)$ct, 'text/html') === 0;
}

function http_get_with_headers($url, $cookieFile, $wantBody = true)
{
    $headers = [];
    $ch = curl_init((string)$url);
    if (!$ch) throw new RuntimeException('No se pudo inicializar cURL');

    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_RETURNTRANSFER => (bool)$wantBody,
        CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            return $len;
        },
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; S3Fetcher/1.3)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEFILE     => (string)$cookieFile,
        CURLOPT_COOKIEJAR      => (string)$cookieFile,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 0,
    ]);

    $body = $wantBody ? curl_exec($ch) : null;

    if ($wantBody && $body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("GET $url: $err");
    }

    curl_close($ch);
    return ['headers' => $headers, 'body' => $body];
}

function http_head_or_null($url, $cookieFile)
{
    $headers = [];
    $ch = curl_init((string)$url);
    if (!$ch) return null;

    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            return $len;
        },
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; S3Fetcher/1.3)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEFILE     => (string)$cookieFile,
        CURLOPT_COOKIEJAR      => (string)$cookieFile,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 0,
    ]);

    $ok = curl_exec($ch);
    if ($ok === false) { curl_close($ch); return null; }
    curl_close($ch);
    return $headers;
}

function extraerFilenameDeContentDisposition($cd)
{
    if (!$cd) return null;
    $cd = (string)$cd;

    if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $cd, $m)) return urldecode(trim($m[1], "\"' "));
    if (preg_match('/filename="?([^";]+)"?/i', $cd, $m))   return trim($m[1], "\"' ");
    return null;
}

function deducirNombre($url, array $headers)
{
    $cd = isset($headers['content-disposition']) ? $headers['content-disposition'] : null;
    if ($cd) {
        $n = extraerFilenameDeContentDisposition($cd);
        if ($n) return $n;
    }
    $path = parse_url((string)$url, PHP_URL_PATH);
    $path = $path ? $path : '';
    $base = basename($path);
    if ($base && $base !== '/' && strpos($base, '.') !== false) return $base;
    return 'archivo';
}

function mimeToExtension($mime)
{
    if (!$mime) return null;
    static $map = [
        'video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv',
        'audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/wav'=>'wav','audio/ogg'=>'ogg',
        'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif',
        'application/pdf'=>'pdf','text/plain'=>'txt','text/html'=>'html',
        'application/zip'=>'zip','application/x-zip-compressed'=>'zip',
        'application/octet-stream'=>'bin'
    ];
    $m = strtolower((string)$mime);
    return isset($map[$m]) ? $map[$m] : null;
}

// ===================== Main =====================
try {
    // Acepta u64 (base64) o url normal
    $u64 = isset($_POST['u64']) ? $_POST['u64'] : (isset($_GET['u64']) ? $_GET['u64'] : '');
    if ($u64 !== '') {
        $decoded = base64_decode((string)$u64, true);
        if ($decoded === false) respond(400, false, ['error' => 'u64 inválido']);
        $urlOriginal = trim($decoded);
    } else {
        $urlOriginal = trim((string)(isset($_POST['url']) ? $_POST['url'] : (isset($_GET['url']) ? $_GET['url'] : '')));
    }

    if ($urlOriginal === '') respond(400, false, ['error' => 'Falta parámetro url/u64']);

    // Ruta de destino desde sesión
    $carpetaSesion = (isset($_SESSION['ruta_actual']) && $_SESSION['ruta_actual'] !== '')
        ? trim((string)$_SESSION['ruta_actual'], '/')
        : (defined('Config::RUTA_COMPARTIDA') ? trim((string)Config::RUTA_COMPARTIDA, '/') : 'Data/Compartidos');

    // Normalizar Drive
    $cookieFile     = tempnam(sys_get_temp_dir(), 'gdc_');
    $urlFinalIntent = driveNormalizeToUc($urlOriginal);

    // 1) Primer GET para ver si requiere confirm (solo Drive)
    $r1   = http_get_with_headers($urlFinalIntent, $cookieFile, true);
    $ct1  = strtolower((string)($r1['headers']['content-type'] ?? ''));

    if (strpos($urlFinalIntent, 'drive.google.com') !== false && isHtmlContentType($ct1) && is_string($r1['body'])) {
        $id    = driveIdFromUrl($urlFinalIntent);
        if (!$id) $id = driveIdFromUrl($urlOriginal);

        $maybe = $id ? driveConfirmedUrlFromHtml($r1['body'], $id) : null;
        if ($maybe) {
            $urlFinalIntent = $maybe;
        } else {
            respond(422, false, ['error' => 'Google Drive requiere confirmación/login o excede cuota.']);
        }
    }

    // HEAD (si no se puede, seguimos igual)
    $head = http_head_or_null($urlFinalIntent, $cookieFile);

    // Tipo/nombre preliminares
    $contentType    = ($head && isset($head['content-type'])) ? $head['content-type'] : ($r1['headers']['content-type'] ?? 'application/octet-stream');
    $nombreOriginal = deducirNombre($urlFinalIntent, $head ? $head : []);

    // 2) Definir nombre/key antes del streaming (necesario para multipart)
    $ext = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
    if ($ext === '' || $ext === null) {
        $ded = mimeToExtension($contentType);
        $ext = $ded ? $ded : 'bin';
    }

    // PHP 7: random_bytes suele estar, si tu server falla aquí, lo cambiamos por openssl_random_pseudo_bytes.
    $nombreEncriptado = uniqid('f_', true) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $key              = rtrim($carpetaSesion, '/') . '/' . $nombreEncriptado;

    /** @var S3Client $s3 */
    $s3     = Config::getS3();
    $bucket = Config::BUCKET;

    $partSize   = 8 * 1024 * 1024; // 8MB
    $uploadId   = null;
    $partsETags = [];
    $partNumber = 1;
    $buffer     = '';
    $bytesTotal = 0;
    $sniff      = ''; // primeros bytes para detectar HTML

    // 3) Streaming GET final → S3 multipart
    $ch = curl_init($urlFinalIntent);
    if ($ch === false) throw new RuntimeException('No se pudo inicializar cURL streaming');

    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; S3Fetcher/1.3)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_WRITEFUNCTION  => function($curl, $data) use (
            &$buffer, $partSize, &$partNumber, &$partsETags, &$uploadId,
            $s3, $bucket, $key, &$bytesTotal, &$sniff, $nombreOriginal, $contentType
        ) {
            $len = strlen($data);

            // sniff primeros 16KB para detectar HTML
            if (strlen($sniff) < 16384) {
                $need  = 16384 - strlen($sniff);
                $sniff .= substr($data, 0, max(0, $need));
            }

            $buffer     .= $data;
            $bytesTotal += $len;

            // Inicia multipart cuando hay suficiente buffer
            if ($uploadId === null && strlen($buffer) >= $partSize) {
                $init = $s3->createMultipartUpload([
                    'Bucket'      => $bucket,
                    'Key'         => $key,
                    'ContentType' => $contentType ? $contentType : 'application/octet-stream',
                    'ACL'         => 'private',
                    'Metadata'    => ['OriginalName' => mb_substr($nombreOriginal, 0, 1024)],
                ]);
                $uploadId = (string)$init['UploadId'];
            }

            // Subir partes completas
            while ($uploadId !== null && strlen($buffer) >= $partSize) {
                $partData = substr($buffer, 0, $partSize);
                $buffer   = substr($buffer, $partSize);

                $result = $s3->uploadPart([
                    'Bucket'     => $bucket,
                    'Key'        => $key,
                    'UploadId'   => $uploadId,
                    'PartNumber' => $partNumber,
                    'Body'       => $partData,
                ]);

                $partsETags[] = ['PartNumber' => $partNumber, 'ETag' => $result['ETag']];
                $partNumber++;
            }

            return $len;
        },
    ]);

    $ok = curl_exec($ch);
    if ($ok === false) {
        $err = curl_error($ch);
        curl_close($ch);

        if ($uploadId) {
            $s3->abortMultipartUpload(['Bucket'=>$bucket,'Key'=>$key,'UploadId'=>$uploadId]);
        }
        respond(500, false, ['error' => 'cURL streaming: ' . $err]);
    }
    curl_close($ch);

    // Limpia cookie file
    if (is_file($cookieFile)) @unlink($cookieFile);

    // Si parece HTML, abortar
    $sn = strtolower($sniff);
    if (strpos($sn, '<html') !== false || strpos($sn, '<!doctype html') !== false) {
        if ($uploadId) {
            $s3->abortMultipartUpload(['Bucket'=>$bucket,'Key'=>$key,'UploadId'=>$uploadId]);
        }
        respond(422, false, ['error' => 'El origen entregó HTML (bloqueo o login requerido).']);
    }

    // Completar subida
    if ($uploadId !== null) {
        if ($buffer !== '') {
            $result = $s3->uploadPart([
                'Bucket'     => $bucket,
                'Key'        => $key,
                'UploadId'   => $uploadId,
                'PartNumber' => $partNumber,
                'Body'       => $buffer,
            ]);
            $partsETags[] = ['PartNumber' => $partNumber, 'ETag' => $result['ETag']];
        }

        $s3->completeMultipartUpload([
            'Bucket'   => $bucket,
            'Key'      => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $partsETags],
        ]);
    } else {
        // pequeño
        $s3->putObject([
            'Bucket'      => $bucket,
            'Key'         => $key,
            'Body'        => $buffer,
            'ContentType' => $contentType ? $contentType : 'application/octet-stream',
            'ACL'         => 'private',
            'Metadata'    => ['OriginalName' => mb_substr($nombreOriginal, 0, 1024)],
        ]);
    }

    // Insert en DB (incluye Ruta porque es NOT NULL)
    $metadatos = json_encode([
        'ip_origen'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido',
        'referer'        => $_SERVER['HTTP_REFERER'] ?? 'ninguno',
        'fecha_servidor' => date('Y-m-d'),
        'hora_servidor'  => date('H:i:s'),
        'zona_horaria'   => date_default_timezone_get(),
        'usuario_envio'  => $_SESSION['usuario'] ?? 'publico',
        'origen'         => 'url',
        'url_fuente'     => $urlOriginal,
    ], JSON_UNESCAPED_UNICODE);

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $rutaDb = rtrim($carpetaSesion, '/') . '/';
    $tamStr = (string)$bytesTotal;

    // ✅ Agregamos Ruta al INSERT
    $insertSql = "INSERT INTO FileS3 (Nombre, Encriptado, Metadatos, Tamano, Ruta, user_id_) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $db_connection->prepare($insertSql);
    if (!$stmt) {
        respond(500, false, ['error' => 'DB prepare: ' . $db_connection->error]);
    }

    // Tamano como string para BIGINT seguro en PHP 7 (evita overflow en 32-bit)
    if (!$stmt->bind_param("sssssi", $nombreOriginal, $nombreEncriptado, $metadatos, $tamStr, $rutaDb, $userId)) {
        respond(500, false, ['error' => 'DB bind_param falló']);
    }

    if (!$stmt->execute()) {
        respond(500, false, ['error' => 'DB execute: ' . $stmt->error]);
    }

    $stmt->close();

    respond(200, true, [
        'key'              => $key,
        'bytes'            => $bytesTotal,
        'content_type'     => $contentType,
        'nombreOriginal'   => $nombreOriginal,
        'nombreEncriptado' => $nombreEncriptado,
    ]);

} catch (Exception $e) {
    respond(500, false, ['error' => $e->getMessage()]);
} catch (Error $e) { // PHP 7.x
    respond(500, false, ['error' => $e->getMessage()]);
}