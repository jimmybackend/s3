<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Admin\FastDriveControlService;
use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Request;
use Aws\Exception\AwsException;

$app = ApplicationKernel::app();
$request = Request::fromGlobals();
$gatewayMode = $request->queryString('gateway') === '1'
    || $request->postString('gateway') === '1';
$gatewayTarget = 'https://fastdrive.esforzados.com/';
$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');

if (!$session->isSuperAdmin()) {
    http_response_code(403);
    echo 'Acceso reservado al superadmin.';
    exit;
}

$service = new FastDriveControlService($app);
$csrfKey = 'fastdrive_control_csrf';
$csrf = (string)$session->get($csrfKey, '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $session->set($csrfKey, $csrf);
}

$message = '';
$error = '';

if ($request->method() === 'POST') {
    $postedCsrf = $request->postRawString('csrf');
    if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        $error = 'Token CSRF inválido. Recarga la página e inténtalo otra vez.';
    } elseif ($request->postString('action') !== 'start') {
        $error = 'Acción no permitida.';
    } else {
        try {
            $result = $service->start($request->postRawString('current_password'));
            $message = (string)($result['message'] ?? 'Orden enviada a AWS.');
        } catch (AwsException $e) {
            error_log('[FastDrive control] AWS start error: ' . $e->getMessage());
            $error = ($e->getAwsErrorCode() ?: 'AWS') . ': '
                . ($e->getAwsErrorMessage() ?: 'No se pudo encender FastDrive.');
        } catch (Throwable $e) {
            error_log('[FastDrive control] start error: ' . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

$status = null;
try {
    $status = $service->status();
} catch (AwsException $e) {
    error_log('[FastDrive control] AWS status error: ' . $e->getMessage());
    $error = $error !== '' ? $error : (($e->getAwsErrorCode() ?: 'AWS') . ': '
        . ($e->getAwsErrorMessage() ?: 'No se pudo consultar FastDrive.'));
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

$state = is_array($status) ? (string)($status['state'] ?? 'unknown') : 'unknown';
$startAllowed = $state === 'stopped';
$gatewayWaiting = $gatewayMode && in_array($state, ['pending', 'running'], true);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Control FastDrive · ArcadeCloud</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#111827;color:#f9fafb;margin:0;padding:24px}
main{max-width:680px;margin:0 auto;background:#1f2937;border:1px solid #374151;border-radius:16px;padding:24px}
h1{margin-top:0}dl{display:grid;grid-template-columns:160px 1fr;gap:8px 16px}dt{color:#9ca3af}dd{margin:0;word-break:break-word}.ok{background:#064e3b;padding:12px;border-radius:10px}.err{background:#7f1d1d;padding:12px;border-radius:10px}.state{font-weight:700}.actions{margin-top:24px;padding-top:20px;border-top:1px solid #374151}input{width:100%;box-sizing:border-box;padding:12px;border-radius:8px;border:1px solid #4b5563;background:#111827;color:#fff;margin:8px 0 12px}button{padding:12px 18px;border:0;border-radius:8px;font-weight:700;cursor:pointer}button[disabled]{opacity:.5;cursor:not-allowed}a{color:#93c5fd}.note{color:#9ca3af;font-size:.92rem}
</style>
</head>
<body>
<main>
  <?php if (!$gatewayMode): ?><p><a href="s3.php">← Volver al Drive</a></p><?php endif; ?>
  <h1><?= $gatewayMode ? 'Autorizar FastDrive' : 'Control de FastDrive' ?></h1>
  <?php if ($gatewayMode): ?>
    <p class="note">FastDrive está apagado o todavía no responde. Sólo un superadmin autenticado puede autorizar su encendido.</p>
  <?php else: ?>
    <p class="note">Este puente sólo puede consultar y encender la EC2 configurada como FastDrive. No acepta IDs enviados por el navegador.</p>
  <?php endif; ?>

  <?php if ($message !== ''): ?><p class="ok"><?= $escape($message) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="err"><?= $escape($error) ?></p><?php endif; ?>

  <?php if (is_array($status)): ?>
  <dl>
    <dt>Estado</dt><dd class="state"><?= $escape($state) ?></dd>
    <dt>Instancia</dt><dd><code><?= $escape((string)$status['instance_id']) ?></code></dd>
    <dt>Región</dt><dd><?= $escape((string)$status['region']) ?></dd>
    <dt>Tipo</dt><dd><?= $escape((string)$status['instance_type']) ?></dd>
    <dt>Zona</dt><dd><?= $escape((string)$status['availability_zone']) ?></dd>
    <dt>IPv4 privada</dt><dd><?= $escape((string)$status['private_ip']) ?></dd>
    <dt>IPv4 pública</dt><dd><?= $escape((string)$status['public_ip']) ?></dd>
  </dl>
  <?php endif; ?>

  <div class="actions">
    <?php if ($startAllowed): ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="action" value="start">
        <?php if ($gatewayMode): ?><input type="hidden" name="gateway" value="1"><?php endif; ?>
        <label for="current_password">Contraseña actual del superadmin</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        <button type="submit" onclick="return confirm('¿Autorizar el encendido de FastDrive?');">Encender FastDrive</button>
      </form>
    <?php elseif ($state === 'running' || $state === 'pending'): ?>
      <p><strong>FastDrive ya está encendido o iniciándose.</strong></p>
    <?php else: ?>
      <button type="button" disabled>Encender FastDrive</button>
      <p class="note">El botón sólo se habilita cuando AWS reporta la instancia como <code>stopped</code>.</p>
    <?php endif; ?>
  </div>
</main>
<?php if ($gatewayWaiting): ?>
<script>
setTimeout(function () {
  window.location.replace(<?= json_encode($gatewayTarget, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
}, 4000);
</script>
<?php endif; ?>
</body>
</html>
