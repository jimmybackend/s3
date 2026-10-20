<?php
declare(strict_types=1);
require_once __DIR__ . '/app_bootstrap.php';
$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');
$userId = $session->userId();
$csrf = (string)$session->get('upload_csrf', '');
if (!preg_match('/\A[a-f0-9]{64}\z/', $csrf)) {
    $csrf = bin2hex(random_bytes(32));
    $session->set('upload_csrf', $csrf);
}
$root = $app->userStorageProvisioner()->ensureRoot($userId);
$destinations = [$root => 'Raíz / ' . $root];
foreach ($app->folderRepository()->listHierarchyRows($userId) as $row) {
    if (!is_array($row)) continue;
    $prefix = (string)($row['Prefix'] ?? '');
    if ($prefix === '') continue;
    try {
        $route = $app->uploadDestinationService()->resolve($userId, $prefix);
        $destinations[$route] = (string)($row['Nombre'] ?? 'Carpeta') . ' — ' . $route;
    } catch (Throwable) {
        // Ignore inaccessible destinations.
    }
}
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src blob:; media-src blob:; base-uri 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Captura móvil · ArcadeCloud</title><link rel="stylesheet" href="css/mobile-capture.css"></head>
<body><main>
<a href="so.php">← Volver a ArcadeCloud</a>
<h1>Captura móvil</h1>
<p>Graba una nota de voz o toma una fotografía, elige la carpeta y pulsa Guardar.</p>
<label for="folder">Carpeta de destino</label>
<select id="folder">
<?php foreach ($destinations as $route => $label): ?>
<option value="<?= $h($route) ?>"><?= $h($label) ?></option>
<?php endforeach; ?>
</select>
<section>
<h2>Nota de voz</h2>
<div class="actions">
<button id="record">🎙 Grabar</button><button id="stop-record" disabled>Detener</button><button id="save-audio" disabled>Guardar nota</button>
</div>
<audio id="audio-preview" controls hidden></audio>
</section>
<section>
<h2>Fotografía</h2>
<div class="actions">
<button id="open-camera">📷 Cámara</button><button id="snap" disabled>Tomar foto</button><button id="close-camera" disabled>Cerrar</button>
</div>
<video id="camera" autoplay playsinline muted hidden></video>
<canvas id="frame" hidden></canvas><img id="image-preview" alt="Fotografía pendiente de guardar" hidden>
<div class="actions">
<label class="picker" for="select-photo">Elegir de galería</label><input type="file" id="select-photo" accept="image/jpeg,image/png,image/webp" hidden>
<button id="save-photo" disabled>Guardar foto</button></div>
</section>
<p id="result" role="status" aria-live="polite">Los archivos se envían solamente al pulsar Guardar.</p>
<input type="hidden" id="upload-token" value="<?= $h($csrf) ?>">
</main><script defer src="js/mobile-capture.js"></script></body></html>