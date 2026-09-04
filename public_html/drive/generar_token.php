<?php
// generar_token.php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

// No muestres warnings en salida para no romper el JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

function jserr(string $msg, int $code = 500, array $extra = []) {
  http_response_code($code);
  echo json_encode(array_merge(['estado'=>'error','mensaje'=>$msg], $extra), JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $ROOT = __DIR__;
  require_once __DIR__ . '/app_bootstrap.php';
  // require_once $ROOT . '/S3Manager.php'; // si lo usas aquí
} catch (Throwable $e) {
  jserr('Bootstrap error: '.$e->getMessage(), 500);
}

date_default_timezone_set('America/Merida');

// ---------- Entrada ----------
$archivo = $_POST['archivo'] ?? '';
$tipo    = $_POST['tipo'] ?? 'otro';
$diasInp = isset($_POST['dias']) ? (int)$_POST['dias'] : 1;
$dias    = max(1, $diasInp);

if ($archivo === '') {
  jserr('Falta parámetro "archivo"', 400);
}

// ---------- Expiración ----------
try {
  $ahora  = new DateTime('now', new DateTimeZone('America/Merida'));
  $expira = clone $ahora;
  $expira->setTime(23, 59, 59);
  if ($dias > 1) $expira->modify('+' . ($dias - 1) . ' day');

  $calendario = [];
  $cursor = clone $ahora;
  for ($i=0; $i<$dias; $i++) {
    $calendario[] = $cursor->format('Y-m-d');
    $cursor->modify('+1 day');
  }
} catch (Throwable $e) {
  jserr('Error calculando expiración: '.$e->getMessage(), 500);
}

// ---------- Persistencia ----------
$tokenId   = bin2hex(random_bytes(16));
$tokenFile = $ROOT . '/tokens.json';
$tokens    = [];

if (file_exists($tokenFile)) {
  $raw = @file_get_contents($tokenFile);
  if ($raw !== false) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $tokens = $tmp;
  }
} else {
  // intenta crear el archivo vacío
  if (@file_put_contents($tokenFile, "{}", LOCK_EX) === false) {
    jserr('No puedo crear tokens.json. Verifica permisos de escritura en: '.$tokenFile, 500);
  }
}

$tokens[$tokenId] = [
  'archivo_key' => $archivo,
  'tipo'        => $tipo,
  'dias'        => $dias,
  'calendario'  => $calendario,
  'creado'      => $ahora->format(DateTime::ATOM),
  'expira'      => $expira->format(DateTime::ATOM),
];

$jsonNuevo = json_encode($tokens, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
if ($jsonNuevo === false) jserr('No se pudo codificar JSON de tokens', 500);

if (@file_put_contents($tokenFile, $jsonNuevo, LOCK_EX) === false) {
  jserr('No puedo escribir tokens.json. Verifica permisos en: '.$tokenFile, 500);
}

// ---------- URL pública ----------
$endpoint = 'token_texto.php';
switch ($tipo) {
  case 'audio':  $endpoint = 'token_audio.php'; break;
  case 'video':  $endpoint = 'token_video.php'; break;
  case 'imagen': $endpoint = 'token_texto.php'; break; // ajusta si tienes token_imagen.php
}

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$baseUrl = $scheme . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
$urlFinal = $baseUrl . $endpoint . '?t=' . $tokenId;

// ---------- Respuesta ----------
echo json_encode([
  'estado'     => 'ok',
  'url'        => $urlFinal,
  'token'      => $tokenId,
  'expira_iso' => $expira->format(DateTime::ATOM),
  'dias'       => $dias,
  'calendario' => $calendario
], JSON_UNESCAPED_SLASHES);
