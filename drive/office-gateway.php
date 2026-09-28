<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Office\OfficeGatewayService;
use Aws\Exception\AwsException;

if ((string)($_SERVER['ARCADECLOUD_OFFICE_GATE'] ?? '') !== '1') {
    http_response_code(404);
    exit;
}

$app = ApplicationKernel::app();
$session = $app->session();
$session->start();
$office = new OfficeGatewayService($app);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$action = (string)($_SERVER['ARCADECLOUD_OFFICE_GATE_ACTION'] ?? 'view');
$officeUserId = (int)$session->get('office_user_id', 0);
$officeSessionKey = strtolower(trim((string)$session->get('office_session_key', '')));
$officeInstanceId = trim((string)$session->get('office_instance_id', ''));
$officeFileId = max(0, (int)$session->get('office_file_id', 0));
$officeDocumentSessionId = strtolower(trim((string)$session->get('office_document_session_id', '')));
$officeDocumentToken = strtolower(trim((string)$session->get('office_document_token', '')));
$officeDocumentName = trim((string)$session->get('office_document_name', ''));

if ($officeUserId > 0 && !preg_match('/^[a-f0-9]{64}$/', $officeSessionKey)) {
    $officeSessionKey = bin2hex(random_bytes(32));
    $session->set('office_session_key', $officeSessionKey);
}

if ($action === 'auth') {
    if (
        $officeUserId <= 0
        || !preg_match('/^[a-f0-9]{64}$/', $officeSessionKey)
        || !preg_match('/^i-[0-9a-f]{8,17}$/i', $officeInstanceId)
    ) {
        http_response_code(401);
        exit;
    }

    try {
        $office->assertOfficeSession($officeUserId, $officeInstanceId, $officeSessionKey);
        http_response_code(204);
        exit;
    } catch (Throwable $e) {
        error_log('[Office gateway] auth error: ' . $e->getMessage());
        http_response_code(401);
        exit;
    }
}

if ($action === 'document-sync' || $action === 'document-close') {
    if (
        $officeUserId <= 0
        || !preg_match('/^[a-f0-9]{64}$/', $officeSessionKey)
        || !preg_match('/^i-[0-9a-f]{8,17}$/i', $officeInstanceId)
        || !preg_match('/^[a-f0-9]{32}$/', $officeDocumentSessionId)
        || !preg_match('/^[a-f0-9]{64}$/', $officeDocumentToken)
    ) {
        $json(['ok' => false, 'error' => 'Sesión documental Office no autorizada.'], 401);
    }
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        $json(['ok' => false, 'error' => 'Método no permitido.'], 405);
    }

    try {
        $office->assertOfficeSession($officeUserId, $officeInstanceId, $officeSessionKey);
        $nodeStatus = $office->nodeStatus();
        $privateIp = trim((string)($nodeStatus['private_ip'] ?? ''));
        if ($privateIp === '') {
            throw new RuntimeException('El nodo Office no publicó su IP privada.');
        }

        $result = $action === 'document-close'
            ? $office->closeDocument($privateIp, $officeDocumentSessionId, $officeDocumentToken)
            : $office->syncDocument($privateIp, $officeDocumentSessionId, $officeDocumentToken);

        if ($action === 'document-close') {
            $office->releaseOfficeSession($officeUserId, $officeInstanceId, $officeSessionKey);
            foreach ([
                'office_document_session_id',
                'office_document_token',
                'office_document_file_id',
                'office_document_name',
            ] as $key) {
                $session->remove($key);
            }
        }

        $json(['ok' => true, 'document' => $result]);
    } catch (Throwable $e) {
        error_log('[Office gateway] document sync error: ' . $e->getMessage());
        $json(['ok' => false, 'error' => $e->getMessage()], 409);
    }
}

if ($action === 'activity' || $action === 'idle') {
    if (
        $officeUserId <= 0
        || !preg_match('/^[a-f0-9]{64}$/', $officeSessionKey)
        || !preg_match('/^i-[0-9a-f]{8,17}$/i', $officeInstanceId)
    ) {
        $json(['ok' => false, 'error' => 'Sesión Office no autorizada.'], 401);
    }

    try {
        if ($action === 'activity') {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                $json(['ok' => false, 'error' => 'Método no permitido.'], 405);
            }
            $office->touchOfficeSession($officeUserId, $officeInstanceId, $officeSessionKey);
            $json(['ok' => true, 'idle' => $office->touchActivity($officeUserId)]);
        }

        $office->assertOfficeSession($officeUserId, $officeInstanceId, $officeSessionKey);
        $json(['ok' => true, 'idle' => $office->idleStatus()]);
    } catch (Throwable $e) {
        error_log('[Office gateway] activity/idle error: ' . $e->getMessage());
        $json(['ok' => false, 'error' => 'No se pudo actualizar el estado del nodo.'], 503);
    }
}

$launch = is_scalar($_GET['launch'] ?? null) ? strtolower(trim((string)$_GET['launch'])) : '';
if ($launch !== '') {
    try {
        $launchContext = $office->consumeLaunchContext($launch);
        $consumedUserId = (int)($launchContext['user_id'] ?? 0);
        $consumedFileId = max(0, (int)($launchContext['file_id'] ?? 0));
        if ($consumedUserId <= 0) {
            $session->set('office_gateway_error', 'El enlace de Office caducó o ya fue utilizado.');
        } else {
            $officeUserId = $consumedUserId;
            $officeFileId = $consumedFileId;
            $session->set('office_user_id', $officeUserId);
            $session->set('office_file_id', $officeFileId);
            foreach ([
                'office_document_session_id',
                'office_document_token',
                'office_document_file_id',
                'office_document_name',
            ] as $key) {
                $session->remove($key);
            }
            if (!preg_match('/^[a-f0-9]{64}$/', $officeSessionKey)) {
                $officeSessionKey = bin2hex(random_bytes(32));
                $session->set('office_session_key', $officeSessionKey);
            }
            $session->remove('office_instance_id');
            $officeInstanceId = '';
            $session->set('office_gateway_started_at', gmdate(DATE_ATOM));
        }
    } catch (Throwable $e) {
        error_log('[Office gateway] launch token error: ' . $e->getMessage());
        $session->set('office_gateway_error', 'No se pudo validar el lanzamiento de Office.');
    }

    header('Location: /', true, 303);
    exit;
}

$csrfKey = 'office_gateway_csrf';
$csrf = (string)$session->get($csrfKey, '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $session->set($csrfKey, $csrf);
}

if ($action === 'start') {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    if ($officeUserId <= 0) {
        $session->set('office_gateway_error', 'Abre Office desde ArcadeCloud Drive o FastDrive.');
        header('Location: /', true, 303);
        exit;
    }

    $postedCsrf = is_scalar($_POST['csrf'] ?? null) ? (string)$_POST['csrf'] : '';
    if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        $session->set('office_gateway_error', 'La autorización caducó. Vuelve a intentarlo.');
        header('Location: /', true, 303);
        exit;
    }

    try {
        $password = is_scalar($_POST['current_password'] ?? null)
            ? (string)$_POST['current_password']
            : '';

        $office->authorizeAndStart(
            $password,
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );
        $session->set('office_gateway_message', 'Autorización correcta. El nodo Office se está iniciando.');
        $session->set($csrfKey, bin2hex(random_bytes(32)));
    } catch (RuntimeException $e) {
        $session->set('office_gateway_error', $e->getMessage());
    } catch (AwsException $e) {
        error_log('[Office gateway] AWS start error: ' . $e->getMessage());
        $session->set('office_gateway_error', 'AWS no pudo iniciar el nodo de cómputo.');
    } catch (Throwable $e) {
        error_log('[Office gateway] start error: ' . $e->getMessage());
        $session->set('office_gateway_error', 'No se pudo completar la autorización.');
    }

    header('Location: /', true, 303);
    exit;
}

$error = (string)$session->get('office_gateway_error', '');
$message = (string)$session->get('office_gateway_message', '');
$session->remove('office_gateway_error');
$session->remove('office_gateway_message');

$state = 'unknown';
$instanceId = '';
$privateIp = '';
$workstationActive = false;
$waiting = false;
$canStart = false;
$mediaBusy = false;
$officeBusy = false;

if ($officeUserId > 0) {
    try {
        $nodeStatus = $office->nodeStatus();
        $state = (string)($nodeStatus['state'] ?? 'unknown');
        $instanceId = trim((string)($nodeStatus['instance_id'] ?? ''));
        $privateIp = trim((string)($nodeStatus['private_ip'] ?? ''));
        $canStart = $state === 'stopped';
        $waiting = in_array($state, ['pending', 'stopping', 'shutting-down'], true);

        if ($state === 'running') {
            try {
                $prepared = $office->prepareWorkstation($privateIp);
                $workstationActive = (bool)($prepared['active'] ?? false);
                $mediaBusy = (bool)($prepared['media_busy'] ?? false);

                if ($workstationActive) {
                    $office->claimOfficeSession($officeUserId, $instanceId, $officeSessionKey);
                    $officeInstanceId = $instanceId;
                    $session->set('office_instance_id', $officeInstanceId);

                    if ($officeFileId > 0) {
                        $documentMatches = preg_match('/^[a-f0-9]{32}$/', $officeDocumentSessionId)
                            && preg_match('/^[a-f0-9]{64}$/', $officeDocumentToken)
                            && (int)$session->get('office_document_file_id', 0) === $officeFileId;

                        if (!$documentMatches) {
                            $document = $office->createDocumentSession(
                                $officeUserId,
                                $officeFileId,
                                $instanceId
                            );
                            $officeDocumentSessionId = (string)$document['session_id'];
                            $officeDocumentToken = (string)$document['control_token'];
                            $officeDocumentName = (string)$document['name'];

                            $session->set('office_document_session_id', $officeDocumentSessionId);
                            $session->set('office_document_token', $officeDocumentToken);
                            $session->set('office_document_file_id', $officeFileId);
                            $session->set('office_document_name', $officeDocumentName);

                            $office->prepareDocument(
                                $privateIp,
                                $officeDocumentSessionId,
                                $officeDocumentToken
                            );
                        }
                    }
                }
            } catch (RuntimeException $e) {
                $officeBusy = str_contains($e->getMessage(), 'reservado por otra sesión')
                    || str_contains($e->getMessage(), 'solicitado por otra sesión');
                $waiting = !$officeBusy;
                $error = $error !== '' ? $error : $e->getMessage();
            }

            if ($workstationActive && !$officeBusy) {
                try {
                    $office->touchOfficeSession($officeUserId, $officeInstanceId, $officeSessionKey);
                    $office->touchActivity($officeUserId);
                } catch (Throwable $e) {
                    error_log('[Office gateway] initial activity error: ' . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[Office gateway] status error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'No se pudo consultar el nodo de cómputo.';
    }
}

if ($officeUserId <= 0) {
    $mode = 'launch-required';
} elseif ($canStart) {
    $mode = 'start-required';
} elseif ($officeBusy) {
    $mode = 'office-busy';
} elseif ($mediaBusy && !$workstationActive) {
    $mode = 'media-busy';
} elseif ($workstationActive) {
    $mode = 'ready';
} else {
    $mode = 'waiting';
    $waiting = true;
}

header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data: blob:; frame-src 'self'; connect-src 'self' https: wss:; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Office · ArcadeCloud</title>
<style>
:root{color-scheme:dark;--bg:#061726;--panel:#0b2134;--line:#1d5878;--text:#e6f4fb;--soft:#9eb7c8;--accent:#31c6ff;--ok:#61f29b;--danger:#ff7777}
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,sans-serif}
.shell{min-height:100%;display:grid;place-items:center;padding:24px}
.card{width:min(620px,94vw);border:1px solid var(--line);border-radius:16px;background:linear-gradient(180deg,var(--panel),#071a2b);padding:28px;box-shadow:0 24px 70px #0008}
.brand{display:flex;gap:12px;align-items:center;margin-bottom:22px}.brand i{font-style:normal;font-size:30px}.brand strong{font-size:20px}.brand span{display:block;color:var(--soft);font-size:13px;margin-top:3px}
h1{margin:0 0 10px;font-size:28px}p{color:var(--soft);line-height:1.55}.state{margin:18px 0;padding:12px 14px;border:1px solid var(--line);border-radius:10px}
.ok,.err{margin:12px 0;padding:11px 13px;border-radius:9px}.ok{border:1px solid #2f8c59;color:var(--ok)}.err{border:1px solid #9b4040;color:#ffaaaa}
label{display:block;margin:18px 0 8px;font-weight:700}input{width:100%;padding:13px;border:1px solid var(--line);border-radius:9px;background:#03121f;color:var(--text)}
button{width:100%;margin-top:12px;padding:13px;border:1px solid var(--accent);border-radius:9px;background:#0b334b;color:var(--accent);font-weight:800;cursor:pointer}
.office-stage{position:fixed;inset:0;background:#061726}.office-stage iframe{border:0;width:100%;height:100%;display:block}
.idle-warning{position:fixed;z-index:50;inset:0;display:grid;place-items:center;background:#0009}.idle-warning[hidden]{display:none}.idle-card{width:min(480px,90vw);padding:24px;border:1px solid #d49935;border-radius:14px;background:#111c25;text-align:center;box-shadow:0 22px 70px #000b}.idle-count{font-size:42px;color:#ffc660}.idle-card button{max-width:260px}
.small{font-size:12px;color:var(--soft)}
.office-document-badge{position:fixed;z-index:40;right:12px;top:10px;max-width:min(520px,70vw);padding:7px 11px;border:1px solid #2f8c59;border-radius:9px;background:#061726e8;color:#dfffea;font-size:12px;box-shadow:0 6px 24px #0007}
.office-document-badge.is-conflict{border-color:#d49935;color:#ffd993}
</style>
</head>
<body>
<?php if ($mode === 'ready'): ?>
<div class="office-stage">
  <?php if ($officeFileId > 0 && $officeDocumentName !== ''): ?>
  <div class="office-document-badge" id="officeDocumentBadge">
    <?= $escape($officeDocumentName) ?> · sincronizado con ArcadeCloud
  </div>
  <?php endif; ?>
  <iframe id="officeFrame"
          src="/vnc.html?path=websockify&amp;resize=remote&amp;autoconnect=true"
          title="ArcadeCloud Office"
          allow="clipboard-read; clipboard-write"></iframe>
</div>
<div class="idle-warning" id="officeIdleWarning" hidden>
  <div class="idle-card">
    <h2>Office está inactivo</h2>
    <p>Han pasado 10 minutos sin actividad. Guarda tus cambios. El nodo se apagará en:</p>
    <div class="idle-count" id="officeIdleCount">30</div>
    <p>segundos</p>
    <button type="button" id="officeContinue">Seguir usando Office</button>
  </div>
</div>
<script>
(() => {
  const frame = document.getElementById('officeFrame');
  const warning = document.getElementById('officeIdleWarning');
  const count = document.getElementById('officeIdleCount');
  const keep = document.getElementById('officeContinue');
  let lastActivitySent = 0;
  let remaining = null;
  const throttleMs = 20000;
  const hasDocument = <?= ($officeFileId > 0 && preg_match('/^[a-f0-9]{32}$/', $officeDocumentSessionId)) ? 'true' : 'false' ?>;
  const documentBadge = document.getElementById('officeDocumentBadge');

  async function syncDocument() {
    if (!hasDocument) return;
    try {
      const r = await fetch('/__office_document_sync', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'X-Requested-With':'XMLHttpRequest'}
      });
      const data = await r.json();
      if (!r.ok || data?.ok !== true) throw new Error(data?.error || 'No se pudo sincronizar');
      const doc = data.document || {};
      if (documentBadge) {
        if (doc.conflict === true) {
          documentBadge.classList.add('is-conflict');
          documentBadge.textContent = 'Conflicto protegido: ' + String(doc.conflict_name || 'se creó una copia');
        } else {
          documentBadge.classList.remove('is-conflict');
          documentBadge.textContent = <?= json_encode($officeDocumentName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> + ' · guardado en ArcadeCloud';
        }
      }
    } catch (e) {
      if (documentBadge) documentBadge.textContent = 'Pendiente de sincronizar · ' + String(e?.message || '');
    }
  }

  function closeDocument() {
    if (!hasDocument) return;
    try {
      navigator.sendBeacon('/__office_document_close', new Blob([''], {type:'text/plain'}));
    } catch (_) {}
  }

  async function activity(force = false) {
    const now = Date.now();
    if (!force && now - lastActivitySent < throttleMs) return;
    lastActivitySent = now;
    try {
      const r = await fetch('/__office_activity', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'X-Requested-With':'XMLHttpRequest'}
      });
      if (r.ok) warning.hidden = true;
    } catch (_) {}
  }

  function bind(target) {
    if (!target || target.__arcadeOfficeBound) return;
    target.__arcadeOfficeBound = true;
    ['pointerdown','pointermove','keydown','touchstart','wheel'].forEach((eventName) => {
      target.addEventListener(eventName, () => activity(false), {passive:true});
    });
  }

  bind(document);
  frame.addEventListener('load', () => {
    try { bind(frame.contentDocument); } catch (_) {}
    activity(true);
  });

  keep.addEventListener('click', () => activity(true));
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') activity(true);
  });

  async function pollIdle() {
    try {
      const r = await fetch('/__office_idle?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'X-Requested-With':'XMLHttpRequest'}
      });
      const data = await r.json();
      if (!r.ok || data?.ok !== true) return;
      const idle = data.idle || {};
      if (idle.warning === true && String(idle.state || '') === 'running') {
        remaining = Math.max(0, Math.ceil(Number(idle.shutdown_in_seconds || 0)));
        count.textContent = String(remaining);
        warning.hidden = false;
      } else {
        remaining = null;
        warning.hidden = true;
      }
    } catch (_) {}
  }

  setInterval(pollIdle, 5000);
  if (hasDocument) setInterval(syncDocument, 60000);
  window.addEventListener('beforeunload', closeDocument);
  setInterval(() => {
    if (remaining === null) return;
    remaining = Math.max(0, remaining - 1);
    count.textContent = String(remaining);
  }, 1000);

  activity(true);
  pollIdle();
})();
</script>
<?php else: ?>
<div class="shell"><main class="card">
  <div class="brand"><i>☁️</i><div><strong>ArcadeCloud Office</strong><span>Workstation remota bajo demanda</span></div></div>
  <?php if ($mode === 'launch-required'): ?>
    <h1>Abre Office desde ArcadeCloud</h1>
    <p>Esta entrada requiere un lanzamiento temporal desde <strong>Aplicaciones → Office</strong> en Drive o FastDrive.</p>
  <?php elseif ($mode === 'start-required'): ?>
    <h1>Encender nodo Office</h1>
    <p>La EC2 de alto rendimiento está apagada. Para encenderla se requiere la contraseña actual de un superadministrador.</p>
    <div class="state">Estado AWS: <?= $escape($state) ?></div>
    <?php if ($message !== ''): ?><div class="ok"><?= $escape($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="err"><?= $escape($error) ?></div><?php endif; ?>
    <form method="post" action="/__office_start" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
      <label for="current_password">Contraseña del superadministrador</label>
      <input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="4096" required autofocus>
      <button type="submit">Encender y abrir Office</button>
    </form>
  <?php elseif ($mode === 'office-busy'): ?>
    <h1>Office está en uso</h1>
    <p>La primera versión permite una sola sesión de escritorio. Espera a que la sesión actual termine o el nodo se apague por inactividad.</p>
    <?php if ($error !== ''): ?><div class="err"><?= $escape($error) ?></div><?php endif; ?>
    <div class="state">Reintentando automáticamente…</div>
  <?php elseif ($mode === 'media-busy'): ?>
    <h1>Nodo ocupado</h1>
    <p>Hay una tarea multimedia activa. Office esperará para no competir por CPU y memoria con FFmpeg.</p>
    <div class="state">Reintentando automáticamente…</div>
  <?php else: ?>
    <h1>Preparando Office</h1>
    <p>El nodo de cómputo está arrancando o Workstation todavía no responde. No cierres esta pestaña.</p>
    <div class="state">Estado AWS: <?= $escape($state) ?></div>
    <?php if ($message !== ''): ?><div class="ok"><?= $escape($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="err"><?= $escape($error) ?></div><?php endif; ?>
  <?php endif; ?>
  <p class="small">Office se ejecuta en la EC2 grande. La inactividad real de teclado, mouse o toque alimenta el apagado seguro de 10 minutos; una tarea multimedia activa bloquea el apagado.</p>
</main></div>
<?php if ($waiting || $mode === 'media-busy' || $mode === 'office-busy'): ?>
<script>setTimeout(() => location.replace('/'), 4000);</script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
