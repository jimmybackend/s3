<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Admin\FastDriveWakeService;
use ArcadeCloud\Drive\Core\ApplicationKernel;
use Aws\Exception\AwsException;

if ((string)($_SERVER['ARCADECLOUD_FASTDRIVE_GATE'] ?? '') !== '1') {
    http_response_code(404);
    exit;
}

$app = ApplicationKernel::app();
$session = $app->session();
$session->start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$csrfKey = 'fastdrive_wake_csrf';
$csrf = (string)$session->get($csrfKey, '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $session->set($csrfKey, $csrf);
}

$service = new FastDriveWakeService($app);
$error = (string)$session->get('fastdrive_wake_error', '');
$message = (string)$session->get('fastdrive_wake_message', '');
$session->remove('fastdrive_wake_error');
$session->remove('fastdrive_wake_message');

$action = (string)($_SERVER['ARCADECLOUD_FASTDRIVE_GATE_ACTION'] ?? 'status');
if ($action === 'start') {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }

    $postedCsrf = is_scalar($_POST['csrf'] ?? null) ? (string)$_POST['csrf'] : '';
    if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        $session->set('fastdrive_wake_error', 'La autorización caducó. Vuelve a intentarlo.');
        header('Location: /', true, 303);
        exit;
    }

    try {
        $password = is_scalar($_POST['current_password'] ?? null)
            ? (string)$_POST['current_password']
            : '';

        $service->authorizeAndStart(
            $password,
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );

        $session->set('fastdrive_wake_message', 'Autorización correcta. FastDrive se está iniciando.');
        $session->set($csrfKey, bin2hex(random_bytes(32)));
    } catch (RuntimeException $e) {
        $session->set('fastdrive_wake_error', $e->getMessage());
    } catch (AwsException $e) {
        error_log('[FastDrive wake] AWS start error: ' . $e->getMessage());
        $session->set('fastdrive_wake_error', 'AWS no pudo iniciar FastDrive.');
    } catch (Throwable $e) {
        error_log('[FastDrive wake] start error: ' . $e->getMessage());
        $session->set('fastdrive_wake_error', 'No se pudo completar la autorización.');
    }

    header('Location: /', true, 303);
    exit;
}

$status = null;
try {
    $status = $service->status();
} catch (AwsException $e) {
    error_log('[FastDrive wake] AWS status error: ' . $e->getMessage());
    $error = $error !== '' ? $error : 'No se pudo consultar FastDrive en AWS.';
} catch (Throwable $e) {
    error_log('[FastDrive wake] status error: ' . $e->getMessage());
    $error = $error !== '' ? $error : 'No se pudo consultar el estado de FastDrive.';
}

$state = is_array($status) ? (string)($status['state'] ?? 'unknown') : 'unknown';
$canStart = $state === 'stopped';
$waiting = in_array($state, ['pending', 'running', 'stopping', 'shutting-down'], true);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FastDrive · ArcadeCloud</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#08111d;color:#f4f8fc;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.card{width:min(100%,520px);background:#111f31;border:1px solid #2b425f;border-radius:18px;padding:26px;box-shadow:0 24px 70px rgba(0,0,0,.35)}h1{margin:0 0 8px}.muted{color:#a8bbcf}.state{display:inline-block;margin:8px 0 18px;padding:7px 11px;border-radius:999px;background:#1a304b;font-weight:700}.ok,.err{padding:12px 14px;border-radius:10px;margin:14px 0}.ok{background:#0f5132}.err{background:#6b1f2a}label{display:block;margin-top:16px;font-weight:700}input{width:100%;margin:8px 0 14px;padding:13px;border-radius:10px;border:1px solid #3c5776;background:#081421;color:#fff;font-size:1rem}button{width:100%;padding:13px 16px;border:0;border-radius:10px;font-weight:800;font-size:1rem;cursor:pointer}.small{font-size:.9rem;color:#8fa7bf;margin-top:14px}
</style>
</head>
<body>
<main class="card">
  <h1>FastDrive</h1>

  <?php if ($canStart): ?>
    <p class="muted">FastDrive está apagado. Sólo el superadministrador puede autorizar su encendido.</p>
    <div class="state">Estado: apagado</div>
  <?php elseif ($waiting): ?>
    <p class="muted">FastDrive se está preparando. Esta página volverá a intentarlo automáticamente.</p>
    <div class="state">Estado: <?= $escape($state) ?></div>
  <?php else: ?>
    <p class="muted">FastDrive no está disponible en este momento.</p>
    <div class="state">Estado: <?= $escape($state) ?></div>
  <?php endif; ?>

  <?php if ($message !== ''): ?><div class="ok"><?= $escape($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="err"><?= $escape($error) ?></div><?php endif; ?>

  <?php if ($canStart): ?>
  <form method="post" action="/__fastdrive_start" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <label for="current_password">Contraseña del superadministrador</label>
    <input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="4096" required autofocus>
    <button type="submit">Autorizar y encender FastDrive</button>
  </form>
  <p class="small">Cinco intentos fallidos desde la misma IP bloquean nuevos intentos durante 15 minutos.</p>
  <?php endif; ?>
</main>

<?php if ($waiting): ?>
<script>
setTimeout(function () {
  window.location.replace('/');
}, 4000);
</script>
<?php endif; ?>
</body>
</html>
