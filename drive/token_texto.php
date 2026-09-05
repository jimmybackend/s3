<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('America/Merida');

$ROOT = __DIR__;

try {
    require_once __DIR__ . '/app_bootstrap.php';
    require_once $ROOT . '/S3Manager.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error de arranque: ' . $e->getMessage();
    exit;
}

function fail_html(string $msg, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;padding:2rem">'
        . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</div>';
    exit;
}

function presigned_url(string $key): string
{
    global $db_connection;
    $manager = new S3Manager(db: $db_connection);
    return $manager->generarPresignedUrl($key);
}

function is_text_ext(string $key): bool
{
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    $textExts = ['txt','srt','vtt','md','html','htm','json','csv','log','ini','xml','yml','yaml'];
    return in_array($ext, $textExts, true);
}

function is_image_ext(string $key): bool
{
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','gif','webp'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    $key = trim((string)($_POST['archivo'] ?? ''));
    if ($key === '') {
        http_response_code(400);
        echo json_encode(['estado' => 'error', 'mensaje' => 'Archivo no especificado.']);
        exit;
    }

    try {
        $s3 = Config::getS3();
        $bucket = Config::BUCKET;
        $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $key]);

        echo json_encode([
            'estado'    => 'ok',
            'contenido' => (string)$result['Body']
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['estado' => 'error', 'mensaje' => $e->getMessage()]);
    }
    exit;
}

$t         = trim((string)($_GET['t'] ?? ''));
$key       = trim((string)($_GET['archivo'] ?? $_GET['key'] ?? ''));
$tipoToken = null;

if ($t !== '') {
    $tokensFile = $ROOT . '/tokens.json';
    if (!is_file($tokensFile)) {
        fail_html('No existe tokens.json', 500);
    }

    $raw = @file_get_contents($tokensFile);
    $tokens = json_decode((string)$raw, true);

    if (!is_array($tokens) || empty($tokens[$t])) {
        fail_html('Token inválido o inexistente.', 404);
    }

    $tok       = $tokens[$t];
    $key       = trim((string)($tok['archivo_key'] ?? ''));
    $tipoToken = $tok['tipo'] ?? null;
    $expIso    = $tok['expira'] ?? null;

    if ($key === '') {
        fail_html('Token sin archivo asociado.', 500);
    }

    if (!empty($expIso)) {
        try {
            $now = new DateTime('now', new DateTimeZone('America/Merida'));
            $exp = new DateTime((string)$expIso, new DateTimeZone('America/Merida'));
            if ($now > $exp) {
                fail_html('El enlace ha expirado.', 410);
            }
        } catch (Throwable $e) {
        }
    }
}

if ($key === '') {
    fail_html('Archivo no especificado.', 400);
}

try {
    $url = presigned_url($key);
} catch (Throwable $e) {
    fail_html('No se pudo generar la URL presignada: ' . $e->getMessage(), 500);
}

if (isset($_GET['direct']) || isset($_GET['download'])) {
    header('Location: ' . $url);
    exit;
}

$tratarComoTexto = ($tipoToken === 'texto') || is_text_ext($key);
$tratarComoImagen = ($tipoToken === 'imagen') || is_image_ext($key);

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=UTF-8');

    if ($tratarComoTexto) {
        try {
            $s3 = Config::getS3();
            $bucket = Config::BUCKET;
            $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $key]);

            echo json_encode([
                'estado'   => 'ok',
                'archivo'  => $key,
                'contenido'=> (string)$result['Body']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['estado' => 'error', 'mensaje' => $e->getMessage()]);
        }
    } else {
        echo json_encode([
            'estado'  => 'ok',
            'archivo' => $key,
            'url'     => $url
        ], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
$nombre = basename($key);

if ($tratarComoImagen) {
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Imagen</title>
<style>
  :root{color-scheme:dark}
  body{background:#0b0b0b;color:#fff;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial}
  .wrap{max-width:1100px;margin:0 auto;padding:16px}
  .box{background:#111;padding:12px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.4)}
  img{max-width:100%;height:auto;border-radius:8px;display:block;margin:0 auto;background:#000}
  .meta{opacity:.8;font-size:.9rem;margin-top:8px;word-break:break-all}
  a.btn{display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #888;border-radius:8px;color:#fff;text-decoration:none}
  a.btn:hover{background:#1b1b1b}
</style>
</head>
<body>
  <div class="wrap">
    <h1 style="font-size:1.1rem;font-weight:600;margin:8px 0 12px"><?= htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <div class="box"><img src="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt=""></div>
    <div class="meta">Ruta: <?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <a class="btn" href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Abrir directo</a>
  </div>
</body>
</html>
<?php
    exit;
}

if ($tratarComoTexto) {
    try {
        $s3 = Config::getS3();
        $bucket = Config::BUCKET;
        $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
        $contenido = (string)$result['Body'];
    } catch (Throwable $e) {
        fail_html('No se pudo leer el contenido: ' . $e->getMessage(), 500);
    }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Texto compartido</title>
<style>
  :root{color-scheme:dark}
  body{background:#0b0b0b;color:#fff;margin:0;font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace}
  .wrap{max-width:1100px;margin:0 auto;padding:16px}
  .box{background:#111;padding:16px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.4);overflow:auto}
  pre{white-space:pre-wrap;word-break:break-word;margin:0}
  .meta{opacity:.8;font-size:.9rem;margin-top:8px;word-break:break-all}
  a.btn{display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #888;border-radius:8px;color:#fff;text-decoration:none}
  a.btn:hover{background:#1b1b1b}
</style>
</head>
<body>
  <div class="wrap">
    <h1 style="font-size:1.05rem;font-weight:600;margin:8px 0 12px"><?= htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <div class="box">
      <pre><?= htmlspecialchars($contenido, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
    </div>
    <div class="meta">Ruta: <?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <a class="btn" href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Abrir directo</a>
  </div>
</body>
</html>
<?php
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Archivo</title>
<style>
  :root{color-scheme:dark}
  body{background:#0b0b0b;color:#fff;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial}
  .wrap{max-width:900px;margin:0 auto;padding:16px}
  .box{background:#111;padding:16px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.4)}
  .meta{opacity:.8;font-size:.9rem;margin-top:8px;word-break:break-all}
  a.btn{display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #888;border-radius:8px;color:#fff;text-decoration:none}
  a.btn:hover{background:#1b1b1b}
</style>
</head>
<body>
  <div class="wrap">
    <h1 style="font-size:1.05rem;font-weight:600;margin:8px 0 12px"><?= htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <div class="box">Este archivo no es de texto ni imagen. Puedes abrirlo directamente.</div>
    <div class="meta">Ruta: <?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <a class="btn" href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Abrir directo</a>
  </div>
</body>
</html>