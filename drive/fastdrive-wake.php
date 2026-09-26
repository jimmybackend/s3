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
:root{color-scheme:dark;--bg:#000;--bg2:#070707;--text:#d7d7d7;--text-soft:#b9b9b9;--text-strong:#f2f2f2;--panel:rgba(255,255,255,.035);--border:rgba(255,255,255,.12);--accent:#00ff66;--accent2:#66ff99;--accent-rgb:0,255,102;--danger:#ff5a5a}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:radial-gradient(1200px 700px at 20% 10%,rgba(200,200,200,.10),transparent 60%),radial-gradient(900px 600px at 80% 20%,rgba(160,160,160,.08),transparent 55%),linear-gradient(180deg,#000 0%,#050505 42%,#000 100%);color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.shell{min-height:100vh;display:flex;flex-direction:column}
.topbar{min-height:70px;display:flex;align-items:center;padding:0 20px;border-bottom:1px solid rgba(var(--accent-rgb),.28);background:rgba(0,0,0,.72);backdrop-filter:blur(12px)}
.brand{display:flex;align-items:center;gap:12px;color:var(--text-strong)}
.brand-mark{width:42px;height:42px;border:1px solid rgba(var(--accent-rgb),.5);border-radius:10px;display:grid;place-items:center;color:var(--accent);box-shadow:0 0 18px rgba(var(--accent-rgb),.16);font-weight:900}
.brand-copy strong{display:block;font-size:1.05rem;letter-spacing:.01em}.brand-copy strong span{color:var(--accent)}.brand-copy small{display:block;margin-top:2px;color:var(--text-soft);font-size:.74rem}
.content{flex:1;display:grid;place-items:center;padding:28px 18px 44px}
.card{width:min(100%,620px);background:linear-gradient(180deg,var(--panel),rgba(255,255,255,.018));border:1px solid var(--border);border-radius:14px;padding:28px;box-shadow:0 18px 50px rgba(0,0,0,.35);position:relative;overflow:hidden}
.card:before{content:"";position:absolute;inset:0 0 auto 0;height:2px;background:linear-gradient(90deg,transparent,var(--accent),transparent)}
.eyebrow{color:var(--accent);font-size:.78rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;margin-bottom:8px}
h1{margin:0 0 10px;color:var(--text-strong);font-size:clamp(1.55rem,4vw,2.15rem)}
.muted{color:var(--text-soft);line-height:1.55}
.state{display:inline-flex;align-items:center;gap:8px;margin:10px 0 20px;padding:8px 12px;border:1px solid rgba(var(--accent-rgb),.25);border-radius:999px;background:rgba(var(--accent-rgb),.07);color:var(--text-strong);font-weight:800}
.state-dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 10px rgba(var(--accent-rgb),.7)}
.ok,.err{padding:12px 14px;border-radius:9px;margin:14px 0;border:1px solid}.ok{background:rgba(var(--accent-rgb),.08);border-color:rgba(var(--accent-rgb),.3);color:var(--accent2)}.err{background:rgba(255,90,90,.08);border-color:rgba(255,90,90,.32);color:#ff9a9a}
label{display:block;margin-top:18px;color:var(--text-strong);font-weight:800}
input{width:100%;margin:8px 0 14px;padding:13px 14px;border-radius:9px;border:1px solid var(--border);background:rgba(0,0,0,.58);color:var(--text);font:inherit}
input:focus{outline:none;border-color:rgba(var(--accent-rgb),.65);box-shadow:0 0 10px rgba(var(--accent-rgb),.26),0 0 22px rgba(var(--accent-rgb),.14)}
button{width:100%;padding:13px 16px;border:1px solid rgba(var(--accent-rgb),.6);border-radius:9px;font:inherit;font-weight:900;cursor:pointer;background:rgba(var(--accent-rgb),.12);color:var(--accent);box-shadow:0 0 14px rgba(var(--accent-rgb),.08)}
button:hover{background:rgba(var(--accent-rgb),.18);box-shadow:0 0 16px rgba(var(--accent-rgb),.18)}
.small{font-size:.84rem;color:var(--text-soft);margin:14px 0 0;line-height:1.45}
.footer{padding:0 18px 22px;text-align:center;color:#777;font-size:.75rem}
@media(max-width:560px){.topbar{padding:0 14px;min-height:64px}.brand-mark{width:38px;height:38px}.content{padding:18px 12px 30px}.card{padding:22px 18px;border-radius:12px}}
</style>
</head>
<body>
<div class="shell">
  <header class="topbar">
    <div class="brand" aria-label="ArcadeCloud Drive">
      <div class="brand-mark">AC</div>
      <div class="brand-copy">
        <strong>ArcadeCloud <span>Drive</span></strong>
        <small>FastDrive · nodo de cómputo</small>
      </div>
    </div>
  </header>

  <div class="content">
    <main class="card">
      <div class="eyebrow">ArcadeCloud Federation</div>
      <h1>FastDrive</h1>

      <?php if ($canStart): ?>
        <p class="muted">El nodo de alto rendimiento está apagado. Su encendido requiere autorización del superadministrador.</p>
        <div class="state"><span class="state-dot"></span> Apagado</div>
      <?php elseif ($waiting): ?>
        <p class="muted">FastDrive se está preparando. Esta página volverá a comprobar el nodo automáticamente.</p>
        <div class="state"><span class="state-dot"></span> <?= $escape($state) ?></div>
      <?php else: ?>
        <p class="muted">FastDrive no está disponible en este momento.</p>
        <div class="state"><span class="state-dot"></span> <?= $escape($state) ?></div>
      <?php endif; ?>

      <?php if ($message !== ''): ?><div class="ok"><?= $escape($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="err"><?= $escape($error) ?></div><?php endif; ?>

      <?php if ($canStart): ?>
      <form method="post" action="/__fastdrive_start" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
        <label for="current_password">Contraseña actual del superadministrador</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="4096" required autofocus>
        <button type="submit">Encender FastDrive</button>
      </form>
      <p class="small">El encendido sólo se autoriza con una cuenta superadmin activa. Tras cinco intentos fallidos desde la misma IP se aplicará una espera de 15 minutos.</p>
      <?php endif; ?>
    </main>
  </div>

  <div class="footer">ArcadeCloud Drive · fastdrive.esforzados.com</div>
</div>

<?php if ($waiting): ?>
<script>
setTimeout(function () {
  window.location.replace('/');
}, 4000);
</script>
<?php endif; ?>
</body>
</html>
