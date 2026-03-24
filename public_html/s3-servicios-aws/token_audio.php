<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('America/Merida');

$ROOT = __DIR__;

try {
    require_once $ROOT . '/vendor/autoload.php';
    require_once __DIR__ . '/app_bootstrap.php';
    require_once $ROOT . '/S3Manager.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error de arranque: ' . $e->getMessage();
    exit;
}

function presigned_url(string $key): string
{
    global $db_connection;
    $manager = new S3Manager($db_connection);
    return $manager->generarPresignedUrl($key);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    $key = trim((string)($_POST['archivo'] ?? ''));
    if ($key === '') {
        http_response_code(400);
        echo json_encode(['estado' => 'error', 'mensaje' => 'Archivo no especificado.']);
        exit;
    }

    try {
        $url = presigned_url($key);
        echo json_encode(['estado' => 'ok', 'url' => $url], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['estado' => 'error', 'mensaje' => $e->getMessage()]);
    }
    exit;
}

$t   = trim((string)($_GET['t'] ?? ''));
$key = trim((string)($_GET['archivo'] ?? $_GET['key'] ?? ''));

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

    $tok    = $tokens[$t];
    $key    = trim((string)($tok['archivo_key'] ?? ''));
    $expIso = $tok['expira'] ?? null;

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

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'estado'  => 'ok',
        'url'     => $url,
        'archivo' => $key
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$nombre = basename($key);
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Audio compartido</title>
<style>
  :root{color-scheme:dark}
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#0b0b0b;color:#fff}
  .wrap{max-width:720px;margin:0 auto;padding:16px}
  .box{background:#111;padding:16px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.4)}
  audio{width:100%;outline:none}
  .meta{opacity:.8;font-size:.9rem;margin-top:8px;word-break:break-all}
  a.btn{display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #888;border-radius:8px;color:#fff;text-decoration:none}
  a.btn:hover{background:#1b1b1b}
</style>
</head>
<body>
  <div class="wrap">
    <h1 style="font-size:1.05rem;font-weight:600;margin:8px 0 12px"><?= htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <div class="box">
      <audio controls preload="metadata" src="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></audio>
    </div>
    <div class="meta">
      Ruta: <?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
    <a class="btn" href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Abrir directo</a>
  </div>
</body>
</html>