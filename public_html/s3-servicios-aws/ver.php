<?php
declare(strict_types=1);
ini_set('display_errors','0');
error_reporting(E_ALL);

// Mantén la misma zona horaria usada al generar los tokens
date_default_timezone_set('America/Merida');

$ROOT = __DIR__;

try {
require_once __DIR__ . '/app_bootstrap.php';
  require_once $ROOT . '/S3Manager.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;padding:2rem">'
     . 'Error de arranque: '.htmlspecialchars($e->getMessage()).'</div>';
  exit;
}

function guessTipoPorExt(string $key): string {
  $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
  if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) return 'imagen';
  if (in_array($ext, ['mp4','webm','ogg','mov','avi','mkv'], true)) return 'video';
  if (in_array($ext, ['mp3','wav','ogg','opus','m4a','flac','amr','webm'], true)) return 'audio';
  return 'otro';
}

$t = $_GET['t'] ?? ($_GET['token'] ?? '');
$tokensFile = $ROOT . '/tokens.json';

$error = '';
$data  = null;
$key   = '';
$tipo  = 'otro';
$url   = '';

if ($t === '') {
  $error = 'Falta parámetro de token.';
} elseif (!is_file($tokensFile)) {
  $error = 'No existe tokens.json';
} else {
  $json = @file_get_contents($tokensFile);
  $tokens = json_decode((string)$json, true);
  if (!is_array($tokens) || empty($tokens[$t])) {
    $error = 'Token no válido o inexistente.';
  } else {
    $data = $tokens[$t];
    $key  = $data['archivo_key'] ?? '';
    $tipo = $data['tipo'] ?? guessTipoPorExt($key);

    if ($key === '') {
      $error = 'Token sin archivo asociado.';
    } else {
      // Validar expiración (formato ISO ATOM en tokens.json)
      if (!empty($data['expira'])) {
        try {
          $now = new DateTime('now', new DateTimeZone('America/Merida'));
          $exp = new DateTime($data['expira'], new DateTimeZone('America/Merida'));
          if ($now > $exp) {
            $error = 'El enlace ha expirado.';
          }
        } catch (Throwable $e) {
          // Si falla el parseo, no bloqueamos: seguimos
        }
      }

      // Generar URL presignada si no hay error
      if ($error === '') {
        try {
          $manager = new S3Manager();
          $url = $manager->generarPresignedUrl($key);
        } catch (Throwable $e) {
          $error = 'Error al generar enlace temporal: '.$e->getMessage();
        }
      }
    }
  }
}

// Redirección directa opcional
if ($error === '' && $url && (isset($_GET['direct']) || isset($_GET['download']))) {
  header('Location: '.$url);
  exit;
}

// --- Render ---
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Archivo compartido</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Usa tu Bootstrap; si ya está en el layout, puedes quitar estas hojas -->
  <link rel="stylesheet" href="https://portal.esforzados.com/assets/css/bootstrap.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body { background:#f8f9fa; display:flex; align-items:center; justify-content:center; min-height:100vh; }
    .card { max-width: 700px; width:100%; box-shadow:0 0 15px rgba(0,0,0,.1); }
    .file-preview { max-height:70vh; object-fit:contain; border-radius:.5rem; }
  </style>
</head>
<body>

<div class="card">
  <div class="card-header bg-primary text-white d-flex align-items-center">
    <i class="fas fa-share-alt fa-lg mr-2"></i>
    <h5 class="mb-0 ml-2">Archivo compartido</h5>
  </div>
  <div class="card-body text-center">

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php else: ?>
      <p class="mb-4">
        <strong><i class="fas fa-file-alt mr-2"></i><?= htmlspecialchars(basename($key)) ?></strong>
      </p>

      <?php if ($tipo === 'imagen'): ?>
        <img src="<?= htmlspecialchars($url) ?>" alt="Imagen" class="img-fluid file-preview mb-3">
      <?php elseif ($tipo === 'video'): ?>
        <video src="<?= htmlspecialchars($url) ?>" controls class="w-100 file-preview mb-3"></video>
      <?php elseif ($tipo === 'audio'): ?>
        <audio src="<?= htmlspecialchars($url) ?>" controls class="w-100 mb-3"></audio>
      <?php else: ?>
        <a href="<?= htmlspecialchars($url) ?>" class="btn btn-outline-primary" target="_blank" rel="noopener">
          <i class="fas fa-download mr-1"></i> Abrir / Descargar
        </a>
      <?php endif; ?>

      <?php if (!empty($data['expira'])):
        try {
          $expDT = new DateTime($data['expira']);
          $expLabel = $expDT->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
          $expLabel = htmlspecialchars($data['expira']);
        }
      ?>
        <p class="mt-4 text-muted">
          <i class="far fa-clock"></i>
          Este enlace expira el <strong><?= $expLabel ?></strong>
        </p>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</div>

</body>
</html>
