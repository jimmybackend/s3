<?php
// ec2.php – Panel EC2 con polling, modal de clave, errores visibles y descarga RDP con IP pública
session_start();

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

// ===================== Config =====================
$DEFAULT_REGION = defined('Config::REGION') ? Config::REGION : 'us-east-1';
$region = isset($_GET['region']) && $_GET['region'] !== '' ? $_GET['region'] : $DEFAULT_REGION;
$state  = isset($_GET['state']) ? $_GET['state'] : 'all';

// IDs protegidas + clave
const PROTECTED_INSTANCE_IDS = [
    'i-091f5ddb0e2b42656',
    'i-09d498b788f3a4942',
];
const PROTECTED_PASSWORD = 'Us1317mx@';

// Instancia objetivo para RDP
const RDP_INSTANCE_ID = 'i-025631fcc5c4b7e77';
const RDP_FILE_NAME   = 'Esforzados-Win-S25.rdp';

// CSRF simple
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// ===================== Cliente =====================
class EC2Panel {
    /** @var Ec2Client */
    private $ec2;

    public function __construct($region) {
        $this->ec2 = new Ec2Client([
            'region'      => $region,
            'version'     => 'latest',
            'credentials' => [
                'key'    => Config::ACCESS_KEY,
                'secret' => Config::SECRET_KEY,
            ],
        ]);
    }

    public function listInstances($stateFilter = null) {
        $instances = [];
        $params = [];
        if ($stateFilter && $stateFilter !== 'all') {
            $params['Filters'] = [[ 'Name'=>'instance-state-name', 'Values'=>[$stateFilter] ]];
        }
        do {
            $res = $this->ec2->describeInstances($params);
            foreach (($res['Reservations'] ?? []) as $r) {
                foreach (($r['Instances'] ?? []) as $i) { $instances[] = $i; }
            }
            $params['NextToken'] = isset($res['NextToken']) ? $res['NextToken'] : null;
        } while (!empty($params['NextToken']));
        return $instances;
    }

    public function getInstance($instanceId) {
        $res = $this->ec2->describeInstances(['InstanceIds' => [$instanceId]]);
        $r = $res['Reservations'][0] ?? null;
        return $r ? ($r['Instances'][0] ?? null) : null;
    }

    public function start($instanceId) {
        return $this->ec2->startInstances(['InstanceIds' => [$instanceId]]);
    }

    public function stop($instanceId, $force=false) {
        return $this->ec2->stopInstances([
            'InstanceIds'=>[$instanceId],
            'Force'=>$force
        ]);
    }
}

function getTag($instance, $key) {
    foreach (($instance['Tags'] ?? []) as $t) {
        if (($t['Key'] ?? '') === $key) return (string)($t['Value'] ?? '');
    }
    return '';
}
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_protected_id($id){ return in_array($id, PROTECTED_INSTANCE_IDS, true); }

/**
 * Convierte IPv4 "50.17.162.57" a "ec2-50-17-162-57.compute-1.amazonaws.com"
 * (Como tú lo pediste, fijo compute-1.amazonaws.com)
 */
function ip_to_ec2_dns_compute1($ipv4) {
    $ipv4 = trim((string)$ipv4);
    if ($ipv4 === '') return '';
    // validación básica IPv4
    if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return '';
    $dashed = str_replace('.', '-', $ipv4);
    return 'ec2-' . $dashed . '.compute-1.amazonaws.com';
}

/**
 * Asegura contenido base de RDP si no existe el archivo
 */
function default_rdp_content() {
    return "auto connect:i:1\r\nfull address:s:\r\nusername:s:Administrator\r\n";
}

/**
 * Reemplaza SOLO la línea full address:s:... (si no existe, la agrega)
 */
function set_rdp_full_address($rdpText, $fullAddressHost) {
    $fullAddressHost = (string)$fullAddressHost;

    $hasLine = preg_match('/^full address:s:.*$/mi', $rdpText);
    if ($hasLine) {
        $rdpText = preg_replace('/^full address:s:.*$/mi', 'full address:s:' . $fullAddressHost, $rdpText);
    } else {
        // agrega al inicio (o al final si prefieres)
        $rdpText = rtrim($rdpText, "\r\n") . "\r\nfull address:s:" . $fullAddressHost . "\r\n";
    }
    return $rdpText;
}

// ===================== DOWNLOAD RDP =====================
if (isset($_GET['download']) && $_GET['download'] === 'rdp') {
    $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
    if ($id !== RDP_INSTANCE_ID) {
        http_response_code(403);
        echo "Descarga RDP no permitida para este ID";
        exit;
    }

    $path = __DIR__ . '/' . RDP_FILE_NAME;

    try {
        $panel = new EC2Panel($region);
        $inst  = $panel->getInstance($id);
        if (!$inst) { http_response_code(404); echo "Instancia no encontrada"; exit; }

        $st  = (string)($inst['State']['Name'] ?? 'unknown');
        $pip = (string)($inst['PublicIpAddress'] ?? '');

        if ($st !== 'running') {
            http_response_code(409);
            echo "La instancia no está en running";
            exit;
        }
        if ($pip === '') {
            http_response_code(409);
            echo "Aún no hay IPv4 pública asignada";
            exit;
        }

        $host = ip_to_ec2_dns_compute1($pip);
        if ($host === '') {
            http_response_code(500);
            echo "IPv4 pública inválida: " . $pip;
            exit;
        }

        // Leer o crear template
        if (is_file($path)) {
            $rdp = file_get_contents($path);
        } else {
            $rdp = default_rdp_content();
        }

        // Actualizar full address + guardar al mismo archivo
        $rdp = set_rdp_full_address($rdp, $host);
        file_put_contents($path, $rdp);

        // Descargar el mismo archivo actualizado
        header('Content-Type: application/x-rdp');
        header('Content-Disposition: attachment; filename="' . RDP_FILE_NAME . '"');
        header('X-Content-Type-Options: nosniff');
        echo $rdp;
    } catch (AwsException $e) {
        http_response_code(500);
        echo ($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage());
    } catch (Throwable $t) {
        http_response_code(500);
        echo $t->getMessage();
    }
    exit;
}

// ===================== AJAX =====================
// Estado en vivo
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Falta id']); exit; }
    try {
        $panel = new EC2Panel($region);
        $inst = $panel->getInstance($id);
        if (!$inst) { echo json_encode(['ok'=>false,'error'=>'Instancia no encontrada']); exit; }
        $st   = (string)($inst['State']['Name'] ?? 'unknown');
        $pip  = (string)($inst['PublicIpAddress'] ?? '');
        $prip = (string)($inst['PrivateIpAddress'] ?? '');
        $type = (string)($inst['InstanceType'] ?? '');
        $az   = (string)($inst['Placement']['AvailabilityZone'] ?? '');
        echo json_encode(['ok'=>true,'state'=>$st,'pip'=>$pip,'prip'=>$prip,'type'=>$type,'az'=>$az]);
    } catch (AwsException $e) {
        error_log('AWS status error: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage())]);
    } catch (Throwable $t) {
        error_log('Status error: '.$t->getMessage());
        echo json_encode(['ok'=>false,'error'=>$t->getMessage()]);
    }
    exit;
}

// Acción start/stop
if (isset($_POST['ajax']) && $_POST['ajax'] === 'action') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        echo json_encode(['ok'=>false,'error'=>'CSRF token inválido']); exit;
    }
    $id = isset($_POST['id']) ? $_POST['id'] : '';
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    $pw = isset($_POST['pw']) ? $_POST['pw'] : '';

    if (!$id || !in_array($action, ['start','stop'], true)) {
        echo json_encode(['ok'=>false,'error'=>'Parámetros inválidos']); exit;
    }
    if (is_protected_id($id) && $pw !== PROTECTED_PASSWORD) {
        echo json_encode(['ok'=>false,'error'=>'Clave requerida o incorrecta']); exit;
    }
    try {
        $panel = new EC2Panel($region);
        if ($action === 'start') $panel->start($id); else $panel->stop($id, $force);
        echo json_encode(['ok'=>true,'message'=>($action==='start'?'Se solicitó encender ':'Se solicitó detener ').$id.'.']);
    } catch (AwsException $e) {
        error_log('AWS action error: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage())]);
    } catch (Throwable $t) {
        error_log('Action error: '.$t->getMessage());
        echo json_encode(['ok'=>false,'error'=>$t->getMessage()]);
    }
    exit;
}

// ===================== Render (no-AJAX) =====================
$err = null; $awsErr = null; $list = [];
try {
    $panel = new EC2Panel($region);
    $list = $panel->listInstances($state);
} catch (AwsException $e) {
    $awsErr = ($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage());
} catch (Throwable $t) {
    $err = $t->getMessage();
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>EC2 Panel</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;margin:16px;background:#0b1020;color:#e6e8ef}
h1{margin:0 0 12px 0;font-size:20px}
.card{background:#121833;border:1px solid #1d2445;border-radius:12px;padding:16px;margin-bottom:16px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
label{display:inline-block;margin-right:8px}
input,select,button{background:#0f1530;color:#e6e8ef;border:1px solid #273059;border-radius:8px;padding:8px 10px}
button{cursor:pointer}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{padding:10px;border-bottom:1px solid #202a52;text-align:left;font-size:14px}
th{position:sticky;top:0;background:#0e1430}
.badge{padding:3px 6px;border-radius:999px;font-size:12px;border:1px solid #2d3562}
.state-running{color:#5cf7a1;border-color:#1f6d4f}
.state-stopped{color:#f7b65c;border-color:#6b5127}
.state-other{color:#8ab4ff;border-color:#2a3f7a}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.actions button{margin-right:6px}
.note{font-size:12px;opacity:.85}
.alert{padding:10px;border-radius:8px;margin-bottom:12px}
.alert-ok{background:#0e2a1f;border:1px solid #1f6d4f}
.alert-err{background:#2a0e13;border:1px solid #6d1f2b}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.25);border-top-color:#fff;border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}
/* MODAL */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:999}
.modal{background:#121833;border:1px solid #26305a;border-radius:12px;padding:16px;max-width:360px;width:92%}
.modal h3{margin:0 0 10px 0}
.modal .row{justify-content:flex-end}
.modal-backdrop.show{display:flex}
kbd{background:#0d1230;border:1px solid #26305a;border-radius:6px;padding:2px 6px}
a.rdp{display:inline-block;margin-left:10px;color:#8ab4ff;text-decoration:none;border:1px solid #2a3f7a;padding:4px 8px;border-radius:999px;font-size:12px}
a.rdp:hover{opacity:.9}
</style>
</head>
<body>
    <h1>EC2 Panel</h1>
    <div class="card">
        <form class="row" method="get">
            <label>Región:
                <input type="text" name="region" value="<?= e($region) ?>" placeholder="us-east-1">
            </label>
            <label>Estado:
                <select name="state">
                    <?php
                    $opts = ['all'=>'Todos','running'=>'running','stopped'=>'stopped','pending'=>'pending','stopping'=>'stopping','shutting-down'=>'shutting-down','terminated'=>'terminated'];
                    foreach ($opts as $val=>$label) {
                        $sel = $state===$val ? 'selected' : '';
                        echo "<option value=\"".e($val)."\" $sel>".e($label)."</option>";
                    }
                    ?>
                </select>
            </label>
            <button type="submit">Actualizar</button>
            <span class="note">Credenciales: <code>config/Config.php</code></span>
        </form>
    </div>

    <?php if ($awsErr): ?>
        <div class="alert alert-err"><strong>Error AWS:</strong> <?= e($awsErr) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-err"><strong>Error:</strong> <?= e($err) ?></div>
    <?php endif; ?>

    <div class="card">
        <table id="tbl">
            <thead>
                <tr>
                    <th>Acciones</th><th>ID</th><th>Nombre</th><th>Estado</th><th>Tipo</th><th>AZ</th><th>IPv4 pública</th><th>IPv4 privada</th><th>Launch Time</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$list): ?>
                <tr><td colspan="9">Sin resultados en esta región/filtro.</td></tr>
            <?php else:
                foreach ($list as $i):
                    $id   = (string)($i['InstanceId'] ?? '');
                    $name = getTag($i, 'Name');
                    $st   = (string)($i['State']['Name'] ?? 'unknown');
                    $cls  = $st === 'running' ? 'state-running' : ($st === 'stopped' ? 'state-stopped' : 'state-other');
                    $type = (string)($i['InstanceType'] ?? '');
                    $az   = (string)($i['Placement']['AvailabilityZone'] ?? '');
                    $pip  = (string)($i['PublicIpAddress'] ?? '');
                    $prip = (string)($i['PrivateIpAddress'] ?? '');
                    $lt   = isset($i['LaunchTime']) ? (new DateTime($i['LaunchTime']))->format('Y-m-d H:i:s T') : '';
                    $prot = is_protected_id($id);
                    $isRdp = ($id === RDP_INSTANCE_ID);
            ?>
                <tr id="row-<?= e($id) ?>" data-id="<?= e($id) ?>" data-protected="<?= $prot ? '1':'0' ?>">
                    <td class="actions">
                        <?php if ($st === 'running'): ?>
                            <button data-action="stop" data-id="<?= e($id) ?>">Detener</button>
                            <label class="note"><input type="checkbox" data-force="<?= e($id) ?>"> force</label>
                            <?php if ($isRdp && $pip !== ''): ?>
                                <a class="rdp" href="?download=rdp&id=<?= e($id) ?>&region=<?= e($region) ?>">Descargar RDP</a>
                            <?php endif; ?>
                        <?php elseif ($st === 'stopped'): ?>
                            <button data-action="start" data-id="<?= e($id) ?>">Encender</button>
                        <?php else: ?>
                            <span class="note">Sin acción</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= e($id) ?></code><?= $prot ? ' <span class="badge state-other" title="Protegida">🔒</span>' : '' ?></td>
                    <td><?= e($name) ?></td>
                    <td><span class="badge <?= e($cls) ?>" data-state="<?= e($id) ?>"><?= e($st) ?></span></td>
                    <td data-type="<?= e($id) ?>"><?= e($type) ?></td>
                    <td data-az="<?= e($id) ?>"><?= e($az) ?></td>
                    <td data-pip="<?= e($id) ?>"><?= e($pip) ?></td>
                    <td data-prip="<?= e($id) ?>"><?= e($prip) ?></td>
                    <td><?= e($lt) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal de clave -->
    <div class="modal-backdrop" id="modal">
      <div class="modal">
        <h3>Instancia protegida</h3>
        <p>Esta instancia requiere una clave para proceder.</p>
        <p class="note">Clave: <kbd>U*******@</kbd></p>
        <input type="password" id="pw" placeholder="Clave" autocomplete="current-password" style="width:100%;margin:8px 0">
        <div class="row">
            <button id="cancel" type="button">Cancelar</button>
            <button id="confirm" type="button">Confirmar</button>
        </div>
      </div>
    </div>

<script>
(function(){
  const csrf = "<?= e($csrf) ?>";
  const region = "<?= e($region) ?>";
  const RDP_INSTANCE_ID = "<?= e(RDP_INSTANCE_ID) ?>";
  const modal = document.getElementById('modal');
  const pwInput = document.getElementById('pw');
  const btnCancel = document.getElementById('cancel');
  const btnConfirm = document.getElementById('confirm');
  let pendingAction = null; // {id, action, force}

  function showModal(){
    modal.classList.add('show');
    pwInput.value='';
    setTimeout(()=>pwInput.focus(), 0);
  }
  function hideModal(){
    modal.classList.remove('show');
  }

  btnCancel.addEventListener('click', ()=>{ hideModal(); pendingAction=null; });
  btnConfirm.addEventListener('click', ()=>{
      if (!pendingAction) return;
      doAction(pendingAction.id, pendingAction.action, pendingAction.force, pwInput.value);
      hideModal();
  });

  document.addEventListener('keydown', (ev)=>{
    if (!modal.classList.contains('show')) return;
    if (ev.key === 'Escape') { ev.preventDefault(); hideModal(); pendingAction=null; }
    if (ev.key === 'Enter')  { ev.preventDefault(); btnConfirm.click(); }
  });

  modal.addEventListener('click', (ev)=>{
    if (ev.target === modal) { hideModal(); pendingAction=null; }
  });

  function badgeEl(id){ return document.querySelector('[data-state="'+css(id)+'"]'); }
  function cell(id, kind){ return document.querySelector('[data-'+kind+'="'+css(id)+'"]'); }
  function css(s){ return s.replace(/"/g,'&quot;'); }

  function setProcessing(id, text){
    const b = badgeEl(id);
    if (!b) return;
    b.className = 'badge state-other';
    b.innerHTML = '<span class="spinner"></span>'+text;
  }

  function updateRowFromStatus(id, st, extra){
    const b = badgeEl(id);
    if (!b) return;
    let cls = 'state-other';
    if (st==='running') cls='state-running';
    else if (st==='stopped') cls='state-stopped';
    b.className = 'badge ' + cls;
    b.textContent = st;

    if (extra){
      if (extra.type) cell(id,'type').textContent = extra.type;
      if (extra.az)   cell(id,'az').textContent   = extra.az;
      if (extra.pip!==undefined) cell(id,'pip').textContent  = extra.pip;
      if (extra.prip!==undefined) cell(id,'prip').textContent= extra.prip;
    }

    const row = document.getElementById('row-'+id);
    if (!row) return;
    const actionsCell = row.querySelector('.actions');
    if (!actionsCell) return;

    if (st==='running'){
      const pip = (extra && extra.pip) ? extra.pip : '';
      const rdpLink = (id === RDP_INSTANCE_ID && pip)
        ? `<a class="rdp" href="?download=rdp&id=${encodeURIComponent(id)}&region=${encodeURIComponent(region)}">Descargar RDP</a>`
        : '';

      actionsCell.innerHTML = `
        <button data-action="stop" data-id="${id}">Detener</button>
        <label class="note"><input type="checkbox" data-force="${id}"> force</label>
        ${rdpLink}
      `;
    } else if (st==='stopped'){
      actionsCell.innerHTML = `<button data-action="start" data-id="${id}">Encender</button>`;
    } else {
      actionsCell.innerHTML = `<span class="note">Sin acción</span>`;
    }
  }
 
  async function pollStatus(id, targetFinal){
    const maxSecs = 180;
    const intervalMs = 3000;
    let elapsed = 0;
    while (elapsed <= maxSecs){
      const st = await fetchStatus(id);
      if (st && st.ok){
        updateRowFromStatus(id, st.state, st);
        if (st.state === targetFinal) return true;
      }
      await new Promise(r=>setTimeout(r, intervalMs));
      elapsed += intervalMs/1000;
    }
    return false;
  }

  async function fetchStatus(id){
    try{
      const url = `?ajax=status&id=${encodeURIComponent(id)}&region=${encodeURIComponent(region)}&_=${Date.now()}`;
      const res = await fetch(url, {credentials:'same-origin'});
      return await res.json();
    }catch(e){ return {ok:false,error:String(e)}; }
  }

  async function doAction(id, action, force, pw){
    try{
      const row = document.getElementById('row-'+id);
      const isProtected = row && row.getAttribute('data-protected')==='1';
      const text = action==='start' ? 'encendiéndose...' : 'deteniéndose...';
      setProcessing(id, text);

      const fd = new FormData();
      fd.append('ajax','action');
      fd.append('csrf', csrf);
      fd.append('id', id);
      fd.append('action', action);
      if (force) fd.append('force','1');
      if (isProtected && pw) fd.append('pw', pw);

      const res = await fetch(window.location.href, {method:'POST', body:fd, credentials:'same-origin'});
      const data = await res.json();
      if (!data.ok){
        alert(data.error || 'Error');
        const s = await fetchStatus(id);
        if (s && s.ok) updateRowFromStatus(id, s.state, s);
        return;
      }

      const target = action==='start' ? 'running' : 'stopped';
      await pollStatus(id, target);
    }catch(e){
      alert(String(e));
    }
  }

  document.getElementById('tbl').addEventListener('click', function(ev){
    const btn = ev.target.closest('button[data-action]');
    if (!btn) return;
    ev.preventDefault();

    const action = btn.getAttribute('data-action');
    const id = btn.getAttribute('data-id');
    const row = document.getElementById('row-'+id);
    const isProtected = row && row.getAttribute('data-protected')==='1';
    const forceBox = document.querySelector('input[type="checkbox"][data-force="'+id+'"]');
    const force = !!(forceBox && forceBox.checked);

    if (action==='stop' && !confirm('¿Detener '+id+'?')) return;

    if (isProtected){
      pendingAction = {id, action, force};
      showModal();
      return;
    }

    doAction(id, action, force, '');
  }, false);

})();
</script>
</body>
</html>
