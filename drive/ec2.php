<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\PersonalAwsPageRenderer;
use ArcadeCloud\Drive\View\Ec2PanelHelper as H;
use Aws\Exception\AwsException;

$app = ApplicationKernel::app();
$request = Request::fromGlobals();
$access = $app->personalToolAccessService();
$renderer = new PersonalAwsPageRenderer();
$accessState = $access->state();

if ($accessState === 'forbidden') {
    $renderer->forbidden();
}

if ($accessState === 'locked') {
    $configured = $app->personalAwsConfig()->isConfigured();

    if (
        $request->method() === 'POST'
        && $request->postString('action') === 'unlock'
    ) {
        if ($access->unlock($request->postRawString('access_password'))) {
            header('Location: ' . $request->serverString('PHP_SELF', 'ec2.php'));
            exit;
        }

        $renderer->locked($configured, 'Contraseña incorrecta.');
    }

    $renderer->locked($configured);
}

// ===================== Config =====================
$DEFAULT_REGION = defined('Config::REGION') ? Config::REGION : 'us-east-1';
$region = isset($_GET['region']) && $_GET['region'] !== '' ? (string)$_GET['region'] : $DEFAULT_REGION;
$state  = isset($_GET['state']) ? (string)$_GET['state'] : 'all';

// Instancias críticas que deben permanecer identificadas/protegidas visualmente.
// Nota: la clave se exige del lado servidor para TODAS las acciones start/stop.
const PROTECTED_INSTANCE_IDS = [
    'i-097146ee51c7f7026', // mailit-click
];

// Bases de datos que deben verse SIEMPRE en este panel y que solo se controlan manualmente.
// IMPORTANTE: aquí ya NO hay horarios. Nada se enciende ni se apaga automáticamente desde este archivo.
const MANUAL_DATABASE_IDS = [
    'esforzados-hub',
];

// Instancia objetivo para RDP
const RDP_INSTANCE_ID = 'i-025631fcc5c4b7e77';
const RDP_FILE_NAME   = 'Esforzados-Win-S25.rdp';

// CSRF simple
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];
$actionPasswordHash = $app->personalAwsConfig()->actionPasswordHash();

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
        $panel = $app->ec2Gateway($region);
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

        $host = H::ipv4ToEc2Dns($pip);
        if ($host === '') {
            http_response_code(500);
            echo "IPv4 pública inválida: " . $pip;
            exit;
        }

        $rdp = is_file($path) ? file_get_contents($path) : H::defaultRdpContent();
        $rdp = H::setRdpFullAddress($rdp, $host);
        file_put_contents($path, $rdp);

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

// ===================== AJAX EC2 =====================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Falta id']); exit; }
    try {
        $panel = $app->ec2Gateway($region);
        $inst = $panel->getInstance($id);
        if (!$inst) { echo json_encode(['ok'=>false,'error'=>'Instancia no encontrada']); exit; }
        $st   = (string)($inst['State']['Name'] ?? 'unknown');
        $pip  = (string)($inst['PublicIpAddress'] ?? '');
        $prip = (string)($inst['PrivateIpAddress'] ?? '');
        $type = (string)($inst['InstanceType'] ?? '');
        $az   = (string)($inst['Placement']['AvailabilityZone'] ?? '');
        echo json_encode(['ok'=>true,'state'=>$st,'pip'=>$pip,'prip'=>$prip,'type'=>$type,'az'=>$az]);
    } catch (AwsException $e) {
        error_log('AWS EC2 status error: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage())]);
    } catch (Throwable $t) {
        error_log('EC2 status error: '.$t->getMessage());
        echo json_encode(['ok'=>false,'error'=>$t->getMessage()]);
    }
    exit;
}

if (isset($_POST['ajax']) && $_POST['ajax'] === 'action') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        echo json_encode(['ok'=>false,'error'=>'CSRF token inválido']); exit;
    }
    $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    $pw = isset($_POST['pw']) ? (string)$_POST['pw'] : '';

    if (!$id || !in_array($action, ['start','stop'], true)) {
        echo json_encode(['ok'=>false,'error'=>'Parámetros inválidos']); exit;
    }
    if ($pw === '' || $actionPasswordHash === '' || !password_verify($pw, $actionPasswordHash)) {
        echo json_encode(['ok'=>false,'error'=>'Clave requerida o incorrecta']); exit;
    }
    try {
        $panel = $app->ec2Gateway($region);
        if ($action === 'start') $panel->start($id); else $panel->stop($id, $force);
        echo json_encode(['ok'=>true,'message'=>($action==='start'?'Se solicitó encender ':'Se solicitó detener ').$id.'.']);
    } catch (AwsException $e) {
        error_log('AWS EC2 action error: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage())]);
    } catch (Throwable $t) {
        error_log('EC2 action error: '.$t->getMessage());
        echo json_encode(['ok'=>false,'error'=>$t->getMessage()]);
    }
    exit;
}

// ===================== AJAX RDS / AURORA MANUAL =====================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rds_status') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Falta id']); exit; }
    if (!H::isManualDatabase($id, MANUAL_DATABASE_IDS)) { echo json_encode(['ok'=>false,'error'=>'Base de datos no permitida en este panel']); exit; }

    try {
        $panel = $app->rdsGateway($region);
        $target = $panel->getDatabaseTarget($id);
        if ($target === null) {
            echo json_encode([
                'ok'=>true,
                'id'=>$id,
                'found'=>false,
                'aws_type'=>'No encontrada',
                'target_type'=>'',
                'status'=>'not_found',
                'engine'=>'',
                'class'=>'',
                'endpoint'=>'',
                'reader_endpoint'=>'',
                'port'=>'',
                'az'=>'',
                'multi_az'=>'',
                'error'=>'No encontrada como RDS Instance ni como Aurora/DB Cluster',
            ]);
            exit;
        }
        echo json_encode(['ok'=>true] + $panel->normalizeTarget($id, $target));
    } catch (AwsException $e) {
        error_log('AWS RDS status error: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage())]);
    } catch (Throwable $t) {
        error_log('RDS status error: '.$t->getMessage());
        echo json_encode(['ok'=>false,'error'=>$t->getMessage()]);
    }
    exit;
}

if (isset($_POST['ajax']) && $_POST['ajax'] === 'rds_action') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        echo json_encode(['ok'=>false,'error'=>'CSRF token inválido']); exit;
    }

    $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $pw = isset($_POST['pw']) ? (string)$_POST['pw'] : '';

    if (!$id || !in_array($action, ['start','stop'], true)) {
        echo json_encode(['ok'=>false,'error'=>'Parámetros inválidos']); exit;
    }
    if (!H::isManualDatabase($id, MANUAL_DATABASE_IDS)) {
        echo json_encode(['ok'=>false,'error'=>'Base de datos no permitida en este panel']); exit;
    }
    if ($pw === '' || $actionPasswordHash === '' || !password_verify($pw, $actionPasswordHash)) {
        echo json_encode(['ok'=>false,'error'=>'Clave requerida o incorrecta']); exit;
    }

    try {
        $panel = $app->rdsGateway($region);
        $target = $panel->getDatabaseTarget($id);
        if ($target === null) {
            echo json_encode(['ok'=>false,'error'=>'Base de datos no encontrada como RDS Instance ni como Aurora/DB Cluster']); exit;
        }

        $type = (string)$target['type'];
        $status = $panel->statusFromTarget($target);

        if ($action === 'start') {
            if (!$panel->isStartable($status)) {
                echo json_encode(['ok'=>false,'error'=>"La base {$id} está en estado '{$status}', no se puede encender desde ese estado."]); exit;
            }
            $panel->startDatabase($id, $type);
            echo json_encode(['ok'=>true,'message'=>"Se solicitó encender la base {$id}."]);
            exit;
        }

        if ($action === 'stop') {
            if (!$panel->isStoppable($status)) {
                echo json_encode(['ok'=>false,'error'=>"La base {$id} está en estado '{$status}', no se puede detener desde ese estado."]); exit;
            }
            $panel->stopDatabase($id, $type);
            echo json_encode(['ok'=>true,'message'=>"Se solicitó detener la base {$id}."]);
            exit;
        }
    } catch (AwsException $e) {
        error_log('AWS RDS action error: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage())]);
    } catch (Throwable $t) {
        error_log('RDS action error: '.$t->getMessage());
        echo json_encode(['ok'=>false,'error'=>$t->getMessage()]);
    }
    exit;
}

// ===================== Render (no-AJAX) =====================
$err = null; $awsErr = null; $rdsErr = null; $list = []; $dbList = [];
try {
    $panel = $app->ec2Gateway($region);
    $list = $panel->listInstances($state);

    // Fallback defensivo: si AWS devuelve una lista vacía con filtro,
    // intenta localizar las instancias personales configuradas por ID.
    if ($list === []) {
        foreach (PROTECTED_INSTANCE_IDS as $configuredId) {
            $instance = $panel->getInstance($configuredId);
            if (!$instance) {
                continue;
            }

            $instanceState = (string)($instance['State']['Name'] ?? 'unknown');
            if ($state === 'all' || $state === '' || $state === $instanceState) {
                $list[] = $instance;
            }
        }
    }
} catch (AwsException $e) {
    $awsErr = ($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage());
} catch (Throwable $t) {
    $err = $t->getMessage();
}

try {
    $rdsPanel = $app->rdsGateway($region);
    $dbList = $rdsPanel->listConfiguredDatabases(MANUAL_DATABASE_IDS);
} catch (AwsException $e) {
    $rdsErr = ($e->getAwsErrorCode()?:'AWS').': '.($e->getAwsErrorMessage()?:$e->getMessage());
} catch (Throwable $t) {
    $rdsErr = $t->getMessage();
}

$stylesVersion = is_file(__DIR__ . '/css/styles.css') ? (int)filemtime(__DIR__ . '/css/styles.css') : 1;
$responsiveVersion = is_file(__DIR__ . '/css/responsive.css') ? (int)filemtime(__DIR__ . '/css/responsive.css') : 1;
$toolVersion = is_file(__DIR__ . '/css/personal-tools.css') ? (int)filemtime(__DIR__ . '/css/personal-tools.css') : 1;
$themeBridgeVersion = is_file(__DIR__ . '/js/theme-state-bridge.js') ? (int)filemtime(__DIR__ . '/js/theme-state-bridge.js') : 1;
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>EC2 + RDS Panel · ArcadeCloud Drive</title>
<link rel="icon" href="ellogo.png" type="image/png">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;margin:16px;background:#0b1020;color:#e6e8ef}
h1{margin:0 0 12px 0;font-size:20px}
h2{margin:0 0 10px 0;font-size:17px}
.card{background:#121833;border:1px solid #1d2445;border-radius:12px;padding:16px;margin-bottom:16px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
label{display:inline-block;margin-right:8px}
input,select,button{background:#0f1530;color:#e6e8ef;border:1px solid #273059;border-radius:8px;padding:8px 10px}
button{cursor:pointer}
button:hover{filter:brightness(1.1)}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{padding:10px;border-bottom:1px solid #202a52;text-align:left;font-size:14px;vertical-align:top}
th{position:sticky;top:0;background:#0e1430}
.badge{padding:3px 6px;border-radius:999px;font-size:12px;border:1px solid #2d3562;display:inline-block;white-space:nowrap}
.state-running{color:#5cf7a1;border-color:#1f6d4f}
.state-stopped{color:#f7b65c;border-color:#6b5127}
.state-other{color:#8ab4ff;border-color:#2a3f7a}
.state-error{color:#ff8a9a;border-color:#8a2a3a}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.actions button{margin-right:6px;margin-bottom:4px}
.note{font-size:12px;opacity:.85}
.muted{opacity:.7}
.alert{padding:10px;border-radius:8px;margin-bottom:12px}
.alert-ok{background:#0e2a1f;border:1px solid #1f6d4f}
.alert-err{background:#2a0e13;border:1px solid #6d1f2b}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.25);border-top-color:#fff;border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:999}
.modal{background:#121833;border:1px solid #26305a;border-radius:12px;padding:16px;max-width:380px;width:92%}
.modal h3{margin:0 0 10px 0}
.modal .row{justify-content:flex-end}
.modal-backdrop.show{display:flex}
kbd{background:#0d1230;border:1px solid #26305a;border-radius:6px;padding:2px 6px}
a.rdp{display:inline-block;margin-left:10px;color:#8ab4ff;text-decoration:none;border:1px solid #2a3f7a;padding:4px 8px;border-radius:999px;font-size:12px}
a.rdp:hover{opacity:.9}
code{word-break:break-all}
</style>
<link rel="stylesheet" href="css/styles.css?v=<?= $stylesVersion ?>">
<link rel="stylesheet" href="css/responsive.css?v=<?= $responsiveVersion ?>">
<link rel="stylesheet" href="css/personal-tools.css?v=<?= $toolVersion ?>">
<script defer src="js/theme-state-bridge.js?v=<?= $themeBridgeVersion ?>"></script>
</head>
<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on personal-tool-page">
<nav class="navbar navbar-expand-lg navbar-dark px-3 drive-navbar">
  <a class="navbar-brand d-flex align-items-center" href="s3.php" title="Volver al Drive">
    <img src="ellogo.png" width="48" height="38" class="drive-brand-logo mr-2" alt="Logo">
    Cloud Drive
  </a>
  <div class="ml-auto d-flex align-items-center flex-wrap">
    <a class="btn btn-outline-info btn-sm mr-2" href="aws.php"><i class="fab fa-aws mr-1"></i>AWS</a>
    <a class="btn btn-outline-light btn-sm" href="s3.php"><i class="fas fa-arrow-left mr-1"></i>Drive</a>
  </div>
</nav>
<main class="personal-tool-shell">
    <h1>Panel AWS · EC2 + RDS Manual</h1>
    <div class="card">
        <form class="row" method="get">
            <label>Región:
                <input type="text" name="region" value="<?= H::e($region) ?>" placeholder="us-east-1">
            </label>
            <label>Estado EC2:
                <select name="state">
                    <?php
                    $opts = ['all'=>'Todos','running'=>'running','stopped'=>'stopped','pending'=>'pending','stopping'=>'stopping','shutting-down'=>'shutting-down','terminated'=>'terminated'];
                    foreach ($opts as $val=>$label) {
                        $sel = $state===$val ? 'selected' : '';
                        echo "<option value=\"".H::e($val)."\" $sel>".H::e($label)."</option>";
                    }
                    ?>
                </select>
            </label>
            <button type="submit">Actualizar</button>
        </form>
    </div>

    <?php if ($awsErr): ?>
        <div class="alert alert-err"><strong>Error AWS EC2:</strong> <?= H::e($awsErr) ?></div>
    <?php endif; ?>
    <?php if ($rdsErr): ?>
        <div class="alert alert-err"><strong>Error AWS RDS:</strong> <?= H::e($rdsErr) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-err"><strong>Error:</strong> <?= H::e($err) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Instancias EC2</h2>
        <table id="tbl">
            <thead>
                <tr>
                    <th>Acciones</th><th>ID</th><th>Nombre</th><th>Estado</th><th>Tipo</th><th>AZ</th><th>IPv4 pública</th><th>IPv4 privada</th><th>Launch Time</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$list): ?>
                <tr><td colspan="9">Sin resultados EC2 en esta región/filtro.</td></tr>
            <?php else:
                foreach ($list as $i):
                    $id   = (string)($i['InstanceId'] ?? '');
                    $name = H::tag($i, 'Name');
                    $st   = (string)($i['State']['Name'] ?? 'unknown');
                    $cls  = $st === 'running' ? 'state-running' : ($st === 'stopped' ? 'state-stopped' : 'state-other');
                    $type = (string)($i['InstanceType'] ?? '');
                    $az   = (string)($i['Placement']['AvailabilityZone'] ?? '');
                    $pip  = (string)($i['PublicIpAddress'] ?? '');
                    $prip = (string)($i['PrivateIpAddress'] ?? '');
                    $launchTime = $i['LaunchTime'] ?? null;
                    if ($launchTime instanceof \DateTimeInterface) {
                        $lt = $launchTime->format('Y-m-d H:i:s T');
                    } elseif (is_string($launchTime) && trim($launchTime) !== '') {
                        try {
                            $lt = (new \DateTime($launchTime))->format('Y-m-d H:i:s T');
                        } catch (\Throwable $dateError) {
                            $lt = $launchTime;
                        }
                    } else {
                        $lt = '';
                    }
                    $prot = H::isProtected($id);
                    $isRdp = ($id === RDP_INSTANCE_ID);
            ?>
                <tr id="row-<?= H::e($id) ?>" data-id="<?= H::e($id) ?>" data-protected="<?= $prot ? '1':'0' ?>">
                    <td class="actions">
                        <?php if ($st === 'running'): ?>
                            <button data-action="stop" data-id="<?= H::e($id) ?>">Detener</button>
                            <label class="note"><input type="checkbox" data-force="<?= H::e($id) ?>"> force</label>
                            <?php if ($isRdp && $pip !== ''): ?>
                                <a class="rdp" href="?download=rdp&id=<?= H::e($id) ?>&region=<?= H::e($region) ?>">Descargar RDP</a>
                            <?php endif; ?>
                        <?php elseif ($st === 'stopped'): ?>
                            <button data-action="start" data-id="<?= H::e($id) ?>">Encender</button>
                        <?php else: ?>
                            <span class="note">Sin acción</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= H::e($id) ?></code><?= $prot ? ' <span class="badge state-other" title="Instancia marcada como protegida">🔒 Protegida</span>' : '' ?></td>
                    <td><?= H::e($name) ?></td>
                    <td><span class="badge <?= H::e($cls) ?>" data-state="<?= H::e($id) ?>"><?= H::e($st) ?></span></td>
                    <td data-type="<?= H::e($id) ?>"><?= H::e($type) ?></td>
                    <td data-az="<?= H::e($id) ?>"><?= H::e($az) ?></td>
                    <td data-pip="<?= H::e($id) ?>"><?= H::e($pip) ?></td>
                    <td data-prip="<?= H::e($id) ?>"><?= H::e($prip) ?></td>
                    <td><?= H::e($lt) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Base de datos RDS / Aurora · control manual</h2>
        <p class="note">Esta sección siempre muestra las bases configuradas en <code>MANUAL_DATABASE_IDS</code>. No usa horario, no hace auto-start y no hace auto-stop.</p>
        <table id="rdsTbl">
            <thead>
                <tr>
                    <th>Acciones</th><th>ID</th><th>Tipo AWS</th><th>Estado</th><th>Engine</th><th>Clase</th><th>Endpoint</th><th>Puerto</th><th>AZ / Multi-AZ</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$dbList): ?>
                <tr><td colspan="9">No hay bases configuradas para control manual.</td></tr>
            <?php else:
                foreach ($dbList as $db):
                    $dbId = (string)$db['id'];
                    $dbStatus = (string)$db['status'];
                    $dbCls = H::databaseStateClass($dbStatus);
                    $found = !empty($db['found']);
                    $targetType = (string)($db['target_type'] ?? '');
                    $endpoint = (string)($db['endpoint'] ?? '');
                    $readerEndpoint = (string)($db['reader_endpoint'] ?? '');
                    $azText = trim((string)($db['az'] ?? ''));
                    $multiAz = trim((string)($db['multi_az'] ?? ''));
            ?>
                <tr id="rds-row-<?= H::e($dbId) ?>" data-rds-id="<?= H::e($dbId) ?>" data-rds-type="<?= H::e($targetType) ?>">
                    <td class="actions rds-actions">
                        <?php if (!$found): ?>
                            <span class="note">No encontrada</span>
                        <?php elseif ($dbStatus === 'available'): ?>
                            <button data-rds-action="stop" data-rds-id="<?= H::e($dbId) ?>">Apagar DB</button>
                        <?php elseif ($dbStatus === 'stopped'): ?>
                            <button data-rds-action="start" data-rds-id="<?= H::e($dbId) ?>">Encender DB</button>
                        <?php else: ?>
                            <span class="note">Sin acción</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= H::e($dbId) ?></code> <span class="badge state-other" title="Base de datos protegida: solo se controla manualmente con clave">🔒 Protegida</span></td>
                    <td data-rds-aws_type="<?= H::e($dbId) ?>"><?= H::e($db['aws_type'] ?? '') ?></td>
                    <td>
                        <span class="badge <?= H::e($dbCls) ?>" data-rds-state="<?= H::e($dbId) ?>"><?= H::e($dbStatus) ?></span>
                        <?php if (!empty($db['error'])): ?><div class="note state-error"><?= H::e($db['error']) ?></div><?php endif; ?>
                    </td>
                    <td data-rds-engine="<?= H::e($dbId) ?>"><?= H::e($db['engine'] ?? '') ?></td>
                    <td data-rds-class="<?= H::e($dbId) ?>"><?= H::e($db['class'] ?? '') ?></td>
                    <td data-rds-endpoint="<?= H::e($dbId) ?>">
                        <?php if ($endpoint !== ''): ?><code><?= H::e($endpoint) ?></code><?php endif; ?>
                        <?php if ($readerEndpoint !== ''): ?><div class="note">Reader: <code><?= H::e($readerEndpoint) ?></code></div><?php endif; ?>
                    </td>
                    <td data-rds-port="<?= H::e($dbId) ?>"><?= H::e($db['port'] ?? '') ?></td>
                    <td data-rds-az="<?= H::e($dbId) ?>"><?= H::e($azText) ?><?= $multiAz !== '' ? '<div class="note">Multi-AZ: '.H::e($multiAz).'</div>' : '' ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</main>

<div class="modal-backdrop" id="modal">
  <div class="modal">
    <h3 id="modalTitle">Confirmar acción AWS</h3>
    <p id="modalText">Para encender o detener se requiere la clave.</p>
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
  const csrf = "<?= H::e($csrf) ?>";
  const region = "<?= H::e($region) ?>";
  const RDP_INSTANCE_ID = "<?= H::e(RDP_INSTANCE_ID) ?>";
  const modal = document.getElementById('modal');
  const modalTitle = document.getElementById('modalTitle');
  const modalText = document.getElementById('modalText');
  const pwInput = document.getElementById('pw');
  const btnCancel = document.getElementById('cancel');
  const btnConfirm = document.getElementById('confirm');
  let pendingAction = null;

  function htmlEscape(s){
    return String(s).replace(/[&<>"']/g, function(ch){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[ch];
    });
  }

  function attrEscape(s){
    return String(s).replace(/\\/g,'\\\\').replace(/"/g,'\\"');
  }

  function showModal(title, text){
    modalTitle.textContent = title || 'Confirmar acción AWS';
    modalText.textContent = text || 'Para encender o detener se requiere la clave.';
    modal.classList.add('show');
    pwInput.value='';
    setTimeout(()=>pwInput.focus(), 0);
  }
  function hideModal(){ modal.classList.remove('show'); }

  btnCancel.addEventListener('click', ()=>{ hideModal(); pendingAction=null; });
  btnConfirm.addEventListener('click', ()=>{
      if (!pendingAction) return;
      const pw = pwInput.value;
      if (!pw) {
        alert('Ingresa la clave para continuar.');
        pwInput.focus();
        return;
      }
      const p = pendingAction;
      pendingAction = null;
      if (p.kind === 'rds') {
        doRdsAction(p.id, p.action, pw);
      } else {
        doAction(p.id, p.action, p.force, pw);
      }
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

  function badgeEl(id){ return document.querySelector('[data-state="'+attrEscape(id)+'"]'); }
  function cell(id, kind){ return document.querySelector('[data-'+kind+'="'+attrEscape(id)+'"]'); }

  function setProcessing(id, text){
    const b = badgeEl(id);
    if (!b) return;
    b.className = 'badge state-other';
    b.innerHTML = '<span class="spinner"></span>'+htmlEscape(text);
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
      if (extra.type && cell(id,'type')) cell(id,'type').textContent = extra.type;
      if (extra.az && cell(id,'az'))     cell(id,'az').textContent   = extra.az;
      if (extra.pip!==undefined && cell(id,'pip'))   cell(id,'pip').textContent  = extra.pip;
      if (extra.prip!==undefined && cell(id,'prip')) cell(id,'prip').textContent = extra.prip;
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
        <button data-action="stop" data-id="${htmlEscape(id)}">Detener</button>
        <label class="note"><input type="checkbox" data-force="${htmlEscape(id)}"> force</label>
        ${rdpLink}
      `;
    } else if (st==='stopped'){
      actionsCell.innerHTML = `<button data-action="start" data-id="${htmlEscape(id)}">Encender</button>`;
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
      const text = action==='start' ? 'encendiéndose...' : 'deteniéndose...';
      setProcessing(id, text);

      const fd = new FormData();
      fd.append('ajax','action');
      fd.append('csrf', csrf);
      fd.append('id', id);
      fd.append('action', action);
      if (force) fd.append('force','1');
      fd.append('pw', pw || '');

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

  const ec2Table = document.getElementById('tbl');
  if (ec2Table) {
    ec2Table.addEventListener('click', function(ev){
      const btn = ev.target.closest('button[data-action]');
      if (!btn) return;
      ev.preventDefault();

      const action = btn.getAttribute('data-action');
      const id = btn.getAttribute('data-id');
      const forceBox = document.querySelector('input[type="checkbox"][data-force="'+attrEscape(id)+'"]');
      const force = !!(forceBox && forceBox.checked);

      if (action==='stop' && !confirm('¿Detener EC2 '+id+'?')) return;

      pendingAction = {kind:'ec2', id, action, force};
      showModal('Confirmar acción EC2', 'Para encender o detener la instancia '+id+' se requiere la clave.');
    }, false);
  }

  function rdsBadgeEl(id){ return document.querySelector('[data-rds-state="'+attrEscape(id)+'"]'); }
  function rdsCell(id, kind){ return document.querySelector('[data-rds-'+kind+'="'+attrEscape(id)+'"]'); }

  function rdsClassForStatus(status){
    if (status === 'available') return 'state-running';
    if (status === 'stopped') return 'state-stopped';
    if (status === 'not_found' || status === 'error') return 'state-error';
    return 'state-other';
  }

  function setRdsProcessing(id, text){
    const b = rdsBadgeEl(id);
    if (!b) return;
    b.className = 'badge state-other';
    b.innerHTML = '<span class="spinner"></span>'+htmlEscape(text);
  }

  function renderEndpoint(data){
    let html = '';
    if (data.endpoint) html += '<code>'+htmlEscape(data.endpoint)+'</code>';
    if (data.reader_endpoint) html += '<div class="note">Reader: <code>'+htmlEscape(data.reader_endpoint)+'</code></div>';
    return html;
  }

  function renderAz(data){
    let html = htmlEscape(data.az || '');
    if (data.multi_az) html += '<div class="note">Multi-AZ: '+htmlEscape(data.multi_az)+'</div>';
    return html;
  }

  function updateRdsRowFromStatus(id, status, data){
    const b = rdsBadgeEl(id);
    if (!b) return;
    b.className = 'badge ' + rdsClassForStatus(status);
    b.textContent = status;

    if (data){
      if (rdsCell(id,'aws_type')) rdsCell(id,'aws_type').textContent = data.aws_type || '';
      if (rdsCell(id,'engine')) rdsCell(id,'engine').textContent = data.engine || '';
      if (rdsCell(id,'class')) rdsCell(id,'class').textContent = data.class || '';
      if (rdsCell(id,'endpoint')) rdsCell(id,'endpoint').innerHTML = renderEndpoint(data);
      if (rdsCell(id,'port')) rdsCell(id,'port').textContent = data.port || '';
      if (rdsCell(id,'az')) rdsCell(id,'az').innerHTML = renderAz(data);
    }

    const row = document.getElementById('rds-row-'+id);
    if (!row) return;
    const actionsCell = row.querySelector('.rds-actions');
    if (!actionsCell) return;

    if (status === 'available') {
      actionsCell.innerHTML = `<button data-rds-action="stop" data-rds-id="${htmlEscape(id)}">Apagar DB</button>`;
    } else if (status === 'stopped') {
      actionsCell.innerHTML = `<button data-rds-action="start" data-rds-id="${htmlEscape(id)}">Encender DB</button>`;
    } else if (status === 'not_found') {
      actionsCell.innerHTML = `<span class="note">No encontrada</span>`;
    } else {
      actionsCell.innerHTML = `<span class="note">Sin acción</span>`;
    }
  }

  async function fetchRdsStatus(id){
    try{
      const url = `?ajax=rds_status&id=${encodeURIComponent(id)}&region=${encodeURIComponent(region)}&_=${Date.now()}`;
      const res = await fetch(url, {credentials:'same-origin'});
      return await res.json();
    }catch(e){ return {ok:false,error:String(e)}; }
  }

  async function pollRdsStatus(id, targetFinal){
    const maxSecs = 1800;
    const intervalMs = 10000;
    let elapsed = 0;
    while (elapsed <= maxSecs){
      const st = await fetchRdsStatus(id);
      if (st && st.ok){
        updateRdsRowFromStatus(id, st.status, st);
        if (st.status === targetFinal) return true;
      }
      await new Promise(r=>setTimeout(r, intervalMs));
      elapsed += intervalMs/1000;
    }
    return false;
  }

  async function doRdsAction(id, action, pw){
    try{
      const text = action==='start' ? 'encendiéndose...' : 'apagándose...';
      setRdsProcessing(id, text);

      const fd = new FormData();
      fd.append('ajax','rds_action');
      fd.append('csrf', csrf);
      fd.append('id', id);
      fd.append('action', action);
      fd.append('pw', pw || '');

      const res = await fetch(window.location.href, {method:'POST', body:fd, credentials:'same-origin'});
      const data = await res.json();
      if (!data.ok){
        alert(data.error || 'Error');
        const s = await fetchRdsStatus(id);
        if (s && s.ok) updateRdsRowFromStatus(id, s.status, s);
        return;
      }

      const target = action==='start' ? 'available' : 'stopped';
      await pollRdsStatus(id, target);
    }catch(e){
      alert(String(e));
    }
  }

  const rdsTable = document.getElementById('rdsTbl');
  if (rdsTable) {
    rdsTable.addEventListener('click', function(ev){
      const btn = ev.target.closest('button[data-rds-action]');
      if (!btn) return;
      ev.preventDefault();

      const action = btn.getAttribute('data-rds-action');
      const id = btn.getAttribute('data-rds-id');
      const verb = action === 'start' ? 'encender' : 'apagar';

      if (!confirm('¿Deseas '+verb+' manualmente la base de datos '+id+'?')) return;

      pendingAction = {kind:'rds', id, action, force:false};
      showModal('Confirmar acción RDS', 'Para '+verb+' la base de datos '+id+' se requiere la clave.');
    }, false);
  }

})();
</script>
</body>
</html>
