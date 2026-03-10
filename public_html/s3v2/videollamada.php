<?php
// videollamada.php
declare(strict_types=1);

// === Dependencias ===
// ../db.php debe definir $db_connection (mysqli)
// ../config/Config.php define Config (BUCKET, REGION, ACCESS_KEY, SECRET_KEY, getS3(), DEFAULT_USER_ID, KVS_*).
require_once __DIR__ . '/app_bootstrap.php';

use Aws\KinesisVideo\KinesisVideoClient;
use Aws\KinesisVideoSignalingChannels\KinesisVideoSignalingChannelsClient;

// === Sesión para user_id_ ===
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
function current_user_id(): int {
  if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) return (int)$_SESSION['user_id'];
  return defined('Config::DEFAULT_USER_ID') ? (int)Config::DEFAULT_USER_ID : 1;
}
function default_receiver_id(): int {
  // Receptor destino (si no viene ?to_user_id)
  return defined('Config::DEFAULT_USER_ID') ? (int)Config::DEFAULT_USER_ID : 1;
}

// === Helpers JSON ===
function json_ok(array $data = [], int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_error(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}
function ensure_db(mysqli $db): void {
  if ($db->connect_errno) {
    json_error('Error BD: '.$db->connect_error, 500);
  }
  $db->set_charset('utf8mb4');
}

// === S3 helpers (usa tu Config::getS3) ===
function s3_client(): Aws\S3\S3Client {
  if (method_exists('Config','getS3')) return Config::getS3();
  return new Aws\S3\S3Client([
    'region'  => Config::REGION,
    'version' => 'latest',
    'credentials' => ['key'=>Config::ACCESS_KEY,'secret'=>Config::SECRET_KEY],
  ]);
}
function s3_ensure_prefix(Aws\S3\S3Client $s3, string $bucket, string $prefix): void {
  if ($prefix === '' || substr($prefix, -1) !== '/') $prefix .= '/';
  try {
    $s3->putObject([
      'Bucket' => $bucket,
      'Key'    => $prefix,
      'Body'   => '',
      'ContentType' => 'application/x-directory'
    ]);
  } catch (Throwable $e) {
    error_log('[s3_ensure_prefix] '.$e->getMessage());
  }
}
function upsert_folder(mysqli $db, int $userId, string $prefix, string $name, ?string $parent): void {
  // Respetando índice único (user_id_, Prefix)
  $sql = "INSERT INTO S3Folders (user_id_, Prefix, Nombre, ParentPrefix, Found)
          VALUES (?,?,?,?,1)
          ON DUPLICATE KEY UPDATE Found=VALUES(Found), Nombre=VALUES(Nombre), ParentPrefix=VALUES(ParentPrefix)";
  if ($stmt = $db->prepare($sql)) {
    $stmt->bind_param('issss', $userId, $prefix, $name, $parent);
    @$stmt->execute();
    @$stmt->close();
  }
}
function save_fileS3(mysqli $db, string $nombre, string $encriptado, int $tamano, string $ruta, int $userId, array $meta): void {
  $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
  $sql = "INSERT INTO FileS3 (Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, user_id_, Fecha)
          VALUES (?,?,?,?,?,1,'normal',?,NOW())";
  if ($stmt = $db->prepare($sql)) {
    $stmt->bind_param('ssissi', $nombre, $encriptado, $tamano, $metaJson, $ruta, $userId);
    @$stmt->execute();
    @$stmt->close();
  }
}

// === Conexión BD ===
ensure_db($db_connection);

// === Parámetros comunes ===
$callId = isset($_GET['call_id']) ? (string)$_GET['call_id'] : (isset($_POST['call_id']) ? (string)$_POST['call_id'] : '');

// === API internas de esta página ===
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($action !== '') {
  // STATUS: devuelve status de la llamada
  if ($action === 'status') {
    if ($callId === '') json_error('call_id requerido', 422);
    $sql = "SELECT status FROM calls WHERE id=? LIMIT 1";
    if (!$stmt = $db_connection->prepare($sql)) json_error('Error SQL', 500, ['sql_error'=>$db_connection->error]);
    $stmt->bind_param('s', $callId);
    if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); json_error('Error SQL',500,['sql_error'=>$e]); }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) json_error('Llamada no encontrada', 404);
    json_ok(['status'=>$row['status']]);
  }

  // kvsConfig: devuelve configuración KVS WebRTC (VIEWER)
  if ($action === 'kvsConfig') {
    if ($callId === '') json_error('call_id requerido', 422);
    $sql = "SELECT status FROM calls WHERE id=? LIMIT 1";
    if (!$stmt = $db_connection->prepare($sql)) json_error('Error SQL', 500, ['sql_error'=>$db_connection->error]);
    $stmt->bind_param('s', $callId);
    if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); json_error('Error SQL',500,['sql_error'=>$e]); }
    $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
    if (!$row) json_error('Llamada no encontrada', 404);

    $channelName = Config::KVS_CHANNEL_NAME_PREFIX . $callId;
    $sharedCfg = [
      'region'      => Config::REGION,
      'version'     => 'latest',
      'credentials' => ['key'=>Config::ACCESS_KEY,'secret'=>Config::SECRET_KEY],
    ];

    try {
      $kvs = new KinesisVideoClient($sharedCfg);
      $desc = $kvs->describeSignalingChannel(['ChannelName' => $channelName]);
      $channelArn = $desc['ChannelInfo']['ChannelARN'] ?? null;
      if (!$channelArn) json_error('No existe canal de señalización para la llamada', 404);

      $endpoints = $kvs->getSignalingChannelEndpoint([
        'ChannelARN' => $channelArn,
        'SingleMasterChannelEndpointConfiguration' => [
          'Protocols' => ['WSS', 'HTTPS'],
          'Role'      => 'VIEWER',
        ],
      ]);
      $epMap = [];
      foreach (($endpoints['ResourceEndpointList'] ?? []) as $ep) { $epMap[$ep['Protocol']] = $ep['ResourceEndpoint']; }
      if (!isset($epMap['WSS'], $epMap['HTTPS'])) json_error('Endpoints de señalización incompletos', 500);

      $signalingClient = new KinesisVideoSignalingChannelsClient($sharedCfg + ['endpoint' => $epMap['HTTPS']]);
      $iceResp = $signalingClient->getIceServerConfig(['ChannelARN' => $channelArn]);
      $iceServers = [];
      foreach (($iceResp['IceServerList'] ?? []) as $srv) {
        $iceServers[] = [
          'urls' => $srv['Uris'],
          'username' => $srv['Username'] ?? null,
          'credential' => $srv['Password'] ?? null,
        ];
      }

      json_ok([
        'region'        => Config::REGION,
        'channelName'   => $channelName,
        'channelArn'    => $channelArn,
        'wssEndpoint'   => $epMap['WSS'],
        'httpsEndpoint' => $epMap['HTTPS'],
        'iceServers'    => $iceServers,
      ]);
    } catch (Throwable $e) {
      json_error('KVS error: '.$e->getMessage(), 500);
    }
  }

  // ingestKinesisClip: descarga un clip archivado y lo sube a S3 en Data/Videollamadas/Anonimo/
  if ($action === 'ingestKinesisClip' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $streamName = isset($_POST['streamName']) ? trim((string)$_POST['streamName']) : '';
    $streamArn  = isset($_POST['streamArn'])  ? trim((string)$_POST['streamArn'])  : '';
    $startTsStr = isset($_POST['startTs'])    ? trim((string)$_POST['startTs'])    : '';
    $endTsStr   = isset($_POST['endTs'])      ? trim((string)$_POST['endTs'])      : '';
    $callId     = isset($_POST['call_id'])    ? trim((string)$_POST['call_id'])    : '';

    if ($streamName === '' && $streamArn === '') {
      json_error('streamName o streamArn requerido', 422);
    }
    if ($startTsStr === '' || $endTsStr === '') {
      json_error('startTs y endTs requeridos (ISO8601 o epoch)', 422);
    }

    $toTs = function(string $v): int {
      if (ctype_digit($v)) return (int)$v;
      $t = strtotime($v);
      if ($t === false) throw new RuntimeException('Timestamp inválido: '.$v);
      return $t;
    };
    try {
      $startTs = $toTs($startTsStr);
      $endTs   = $toTs($endTsStr);
      if ($endTs <= $startTs) throw new RuntimeException('Rango de tiempo inválido');
    } catch (Throwable $e) {
      json_error($e->getMessage(), 422);
    }

    // Anónimo fijo
    $userId     = 0;
    $rutaPrefix = "Data/Videollamadas/Anonimo/";

    // Asegura carpetas lógicas en BD y en S3
    upsert_folder($db_connection, $userId, 'Data/', 'Data', null);
    upsert_folder($db_connection, $userId, 'Data/Videollamadas/', 'Videollamadas', 'Data/');
    upsert_folder($db_connection, $userId, $rutaPrefix, 'Anonimo', 'Data/Videollamadas/');

    $s3 = s3_client();
    s3_ensure_prefix($s3, Config::BUCKET, 'Data/');
    s3_ensure_prefix($s3, Config::BUCKET, 'Data/Videollamadas/');
    s3_ensure_prefix($s3, Config::BUCKET, $rutaPrefix);

    $random     = bin2hex(random_bytes(8));
    $encriptado = "kv_" . uniqid() . "_{$random}.mp4";
    $localDir   = sys_get_temp_dir();
    $localDest  = $localDir . '/' . $encriptado;
    $s3Key      = $rutaPrefix . $encriptado;

    $kv = new Aws\KinesisVideo\KinesisVideoClient([
      'region'      => Config::REGION,
      'version'     => 'latest',
      'credentials' => ['key'=>Config::ACCESS_KEY, 'secret'=>Config::SECRET_KEY],
    ]);

    try {
      $epResp = $kv->getDataEndpoint([
        'APIName'   => 'GET_CLIP',
        'StreamARN' => $streamArn !== '' ? $streamArn : null,
        'StreamName'=> $streamName !== '' ? $streamName : null,
      ]);
    } catch (Throwable $e) {
      json_error('getDataEndpoint falló: '.$e->getMessage(), 500);
    }
    $archivedEndpoint = $epResp['DataEndpoint'] ?? null;
    if (!$archivedEndpoint) json_error('No se obtuvo DataEndpoint', 500);

    $kva = new Aws\KinesisVideoArchivedMedia\KinesisVideoArchivedMediaClient([
      'region'      => Config::REGION,
      'version'     => 'latest',
      'credentials' => ['key'=>Config::ACCESS_KEY, 'secret'=>Config::SECRET_KEY],
      'endpoint'    => $archivedEndpoint,
    ]);

    try {
      $clipResp = $kva->getClip([
        'StreamName' => $streamName !== '' ? $streamName : null,
        'ClipFragmentSelector' => [
          'FragmentSelectorType' => 'SERVER_TIMESTAMP',
          'TimestampRange' => [
            'StartTimestamp' => new \DateTimeImmutable('@'.$startTs),
            'EndTimestamp'   => new \DateTimeImmutable('@'.$endTs),
          ],
        ],
      ]);
      $body = $clipResp->get('Payload');
      $out  = fopen($localDest, 'wb');
      stream_copy_to_stream($body->detach(), $out);
      fclose($out);
    } catch (Throwable $e) {
      json_error('getClip falló: '.$e->getMessage(), 500);
    }

    // 4) Subir a S3
    try {
      $s3->putObject([
        'Bucket'=>Config::BUCKET,
        'Key'=>$s3Key,
        'SourceFile'=>$localDest,
        'ContentType'=>'video/mp4',
      ]);
      @unlink($localDest);
    } catch (Throwable $e) {
      json_error('Error al subir a S3: ' . $e->getMessage(), 500);
    }

    // Registrar
    $tam = @filesize($localDest) ?: 0; // si ya se borró, puedes calcular antes del unlink si lo prefieres
    save_fileS3(
      $db_connection,
      basename($encriptado),
      $encriptado,
      (int)$tam,
      $rutaPrefix,
      $userId,
      ['call_id'=>$callId ?: null, 'mime'=>'video/mp4', 'origin'=>'s3', 'kinesis'=>true]
    );

    if ($callId !== '') {
      $path = 's3://' . Config::BUCKET . '/' . $s3Key;
      if ($stmt = $db_connection->prepare("INSERT INTO call_messages(call_id, path, created_at) VALUES(?, ?, NOW())")) {
        $stmt->bind_param('ss', $callId, $path);
        @$stmt->execute();
        @$stmt->close();
      }
    }

    $s3Url = "https://".Config::BUCKET.".s3.".Config::REGION.".amazonaws.com/".$s3Key;
    json_ok(['storage'=>'s3','bucket'=>Config::BUCKET,'key'=>$s3Key,'url'=>$s3Url,'ruta'=>$rutaPrefix]);
  }

  // uploadMessage: subida de video grabado en el navegador, SIEMPRE a S3 en Data/Videollamadas/Anonimo/
  if ($action === 'uploadMessage' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_FILES['video'])) json_error('Archivo "video" requerido', 422);
    if (!is_uploaded_file($_FILES['video']['tmp_name'])) json_error('No se recibió archivo válido', 422);

    $orig = $_FILES['video']['name'];
    $tmp  = $_FILES['video']['tmp_name'];
    $mime = $_FILES['video']['type'] ?: 'application/octet-stream';
    $ext  = pathinfo($orig, PATHINFO_EXTENSION) ?: 'webm';
    $size = (int)($_FILES['video']['size'] ?? 0);

    // Anónimo fijo
    $userId = 0;
    $rutaPrefix = "Data/Videollamadas/Anonimo/";
    $random = bin2hex(random_bytes(8));
    $encriptado = "f_" . uniqid() . "_{$random}.{$ext}";

    $usedS3=false; $s3Key=$rutaPrefix.$encriptado; $s3Url=null; $s3Err=null;

    // Asegurar carpetas lógicas en nuestra BD
    upsert_folder($db_connection, $userId, 'Data/', 'Data', null);
    upsert_folder($db_connection, $userId, 'Data/Videollamadas/', 'Videollamadas', 'Data/');
    upsert_folder($db_connection, $userId, $rutaPrefix, 'Anonimo', 'Data/Videollamadas/');

    // === Asegurar "carpetas" en el bucket S3 ===
    $s3 = s3_client();
    s3_ensure_prefix($s3, Config::BUCKET, 'Data/');
    s3_ensure_prefix($s3, Config::BUCKET, 'Data/Videollamadas/');
    s3_ensure_prefix($s3, Config::BUCKET, $rutaPrefix);

    try {
      $s3->putObject([
        'Bucket'      => Config::BUCKET,
        'Key'         => $s3Key,
        'SourceFile'  => $tmp,        // directo desde tmp_name (sin mover a webroot)
        'ContentType' => $mime,
      ]);
      $usedS3 = true;
      $s3Url = "https://".Config::BUCKET.".s3.".Config::REGION.".amazonaws.com/".$s3Key;
    } catch (Throwable $e) {
      $s3Err = $e->getMessage();
      json_error('Error al subir a S3: '.$s3Err, 500);
    }

    // Registrar en FileS3 (origen s3)
    $meta = [
      'call_id' => $callId ?: null,
      'mime'    => $mime,
      'origin'  => 's3',
    ];
    save_fileS3(
      $db_connection,
      $orig,
      $encriptado,
      $size,
      $rutaPrefix,
      $userId,
      $meta
    );

    // (Opcional) vínculo con tabla de mensajes
    if ($callId !== '') {
      $path = 's3://' . Config::BUCKET . '/' . $s3Key;
      if ($stmt = $db_connection->prepare("INSERT INTO call_messages(call_id, path, created_at) VALUES(?, ?, NOW())")) {
        $stmt->bind_param('ss', $callId, $path);
        @$stmt->execute();
        @$stmt->close();
      }
    }

    json_ok(['storage'=>'s3','bucket'=>Config::BUCKET,'key'=>$s3Key,'url'=>$s3Url,'ruta'=>$rutaPrefix]);
  }

  json_error('Acción inválida', 404);
}

?>
<!doctype html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Videollamada</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .video-tile{ background:#000; aspect-ratio:16/9; position:relative; overflow:hidden; display:flex; align-items:center; justify-content:center; }
    .video-tile video{ width:100%; height:100%; object-fit:cover; }
    .video-placeholder{ position:absolute; inset:0; background:url('videollamada-bg.jpg') center/cover no-repeat; filter:brightness(.9); }
    .preview-img{ max-width:100%; max-height:100%; object-fit:cover; }
    .log-box{ height:140px; overflow:auto; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:.9rem; }
    .hidden{ display:none !important; }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg bg-body-tertiary">
    <div class="container-fluid">
      <a class="navbar-brand d-flex align-items-center" href="index.php"><i class="bi bi-house-door me-2"></i> Inicio</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="nav">
        <ul class="navbar-nav me-auto">
          <li class="nav-item"><a class="nav-link" href="videollamada.php<?php echo $callId ? '?call_id='.urlencode($callId) : ''; ?>">Videollamada</a></li>
        </ul>
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-sun-fill"></i>
          <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="themeSwitch"></div>
          <i class="bi bi-moon-stars"></i>
        </div>
      </div>
    </div>
  </nav>

  <main class="container my-4">
    <h1 class="h3 mb-3">Videollamada (Anónimo)</h1>

    <div class="row g-3">
      <div class="col-12 col-lg-8">
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="video-tile mb-3">
              <div class="video-placeholder"></div>
              <video id="localVideo" autoplay muted playsinline></video>
            </div>
            <div class="d-flex gap-2">
              <button id="btnStart" class="btn btn-primary"><i class="bi bi-telephone"></i> Iniciar</button>
              <button id="btnHangup" class="btn btn-outline-danger" disabled><i class="bi bi-telephone-x"></i> Colgar</button>
              <button id="btnRedial" class="btn btn-outline-secondary" disabled><i class="bi bi-telephone-outbound"></i> Reintentar</button>
              <button id="btnLeaveMsg" class="btn btn-success"><i class="bi bi-record-circle"></i> Dejar mensaje</button>
            </div>
          </div>
        </div>

        <div class="card shadow-sm mt-3">
          <div class="card-header">Log</div>
          <div class="card-body">
            <pre id="log" class="log-box mb-0"></pre>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-4">
        <div class="card shadow-sm">
          <div class="card-header">Estado</div>
          <div class="card-body">
            <form id="cfgForm" class="vstack gap-2">
              <div>
                <label class="form-label">Call ID</label>
                <input type="text" class="form-control" name="call_id" id="callId" value="<?php echo htmlspecialchars($callId ?: '', ENT_QUOTES); ?>" placeholder="ID de la llamada">
              </div>
              <div class="d-grid">
                <button type="button" id="btnLoadCfg" class="btn btn-outline-primary">Cargar configuración KVS</button>
              </div>
              <hr>
              <div class="small text-muted">
                Los mensajes grabados se subirán a <code>s3://<?php echo htmlspecialchars(Config::BUCKET, ENT_QUOTES); ?>/Data/Videollamadas/Anonimo/</code>
              </div>
            </form>
          </div>
        </div>

        <div class="card shadow-sm mt-3">
          <div class="card-header">Subir clip de Kinesis</div>
          <div class="card-body">
            <form id="kvsForm" class="vstack gap-2">
              <div class="row g-2">
                <div class="col-12">
                  <label class="form-label">Stream Name (o ARN)</label>
                  <input type="text" class="form-control" name="streamName" placeholder="miStream">
                </div>
                <div class="col-6">
                  <label class="form-label">Inicio (ISO 8601)</label>
                  <input type="text" class="form-control" name="startTs" placeholder="2025-10-17T15:20:00Z">
                </div>
                <div class="col-6">
                  <label class="form-label">Fin (ISO 8601)</label>
                  <input type="text" class="form-control" name="endTs" placeholder="2025-10-17T15:23:00Z">
                </div>
              </div>
              <div class="d-grid">
                <button type="button" id="btnIngest" class="btn btn-outline-success">Traer clip</button>
              </div>
              <div class="small text-muted">
                El clip se guardará en <code>s3://<?php echo htmlspecialchars(Config::BUCKET, ENT_QUOTES); ?>/Data/Videollamadas/Anonimo/</code>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
  (function(){
    const themeSwitch = document.getElementById('themeSwitch');
    if (themeSwitch) {
      const setTheme = (dark)=>document.documentElement.setAttribute('data-bs-theme', dark?'dark':'light');
      themeSwitch.addEventListener('change', e=>setTheme(e.target.checked));
    }
  })();

  function log(msg){ const el=document.getElementById('log'); el.textContent += (msg + "\n"); el.scrollTop = el.scrollHeight; }

  async function loadCfg(){
    const callId = document.getElementById('callId').value.trim();
    if(!callId){ alert('Ingresa Call ID'); return; }
    const res = await fetch(`videollamada.php?action=kvsConfig`, {method:'POST', body:new URLSearchParams({call_id:callId})});
    const j = await res.json();
    if(!j.ok){ alert(j.error||'Error'); return; }
    log('KVS config cargada.');
    return j;
  }

  async function pollStatus(){
    const callId = document.getElementById('callId').value.trim();
    if(!callId) return;
    try{
      const res = await fetch(`videollamada.php?action=status&call_id=${encodeURIComponent(callId)}`);
      const j = await res.json();
      if(j.ok) log('Status: '+j.status);
    }catch(e){}
  }

  let mediaStream;
  async function prepareLocalPreview(){
    try{
      mediaStream = await navigator.mediaDevices.getUserMedia({audio:true, video:true});
      document.getElementById('localVideo').srcObject = mediaStream;
      log('Preview local listo.');
    }catch(e){
      alert('No se pudo acceder a la cámara/micrófono');
    }
  }

  async function startWebRTC(){
    log('Iniciar (placeholder). Integra tu lógica de WebRTC aquí.');
    document.getElementById('btnHangup').disabled = false;
    document.getElementById('btnStart').disabled = true;
  }

  function hangup(){
    log('Colgar (placeholder).');
    document.getElementById('btnHangup').disabled = true;
    document.getElementById('btnStart').disabled = false;
  }

  function redial(){
    log('Reintentar (placeholder).');
  }

  async function onLeaveMsgClick(){
    try{
      const stream = await navigator.mediaDevices.getUserMedia({audio:true, video:true});
      const mediaRecorder = new MediaRecorder(stream);
      const chunks = [];
      mediaRecorder.ondataavailable = ev => { if(ev.data && ev.data.size>0) chunks.push(ev.data); };
      mediaRecorder.onstop = async () => {
        const blob = new Blob(chunks, {type: 'video/webm'});
        const file = new File([blob], 'mensaje.webm', {type:'video/webm'});
        const fd = new FormData();
        const callId = document.getElementById('callId').value.trim();
        if (callId) fd.append('call_id', callId);
        fd.append('video', file);
        const res = await fetch('videollamada.php?action=uploadMessage', {method:'POST', body: fd});
        const j = await res.json();
        if (!j.ok) { alert(j.error || 'Error al subir'); return; }
        log('Subido a S3: ' + j.url);
      };
      mediaRecorder.start();
      setTimeout(()=>mediaRecorder.stop(), 5000);
      log('Grabando 5s…');
    }catch(e){
      alert('No se pudo grabar el mensaje: ' + e.message);
    }
  }

  async function ingestClip(){
    const form = document.getElementById('kvsForm');
    const data = new FormData(form);
    const res = await fetch('videollamada.php?action=ingestKinesisClip', {method:'POST', body: data});
    const j = await res.json();
    if (j.ok) log('Clip subido: ' + j.url);
    else alert(j.error || 'Error trayendo clip');
  }

  async function preparePage(){
    const ok = await prepareLocalPreview();
  }

  document.addEventListener('DOMContentLoaded', async ()=>{
    document.getElementById('btnLoadCfg').addEventListener('click', loadCfg);
    document.getElementById('btnIngest').addEventListener('click', ingestClip);

    const hasCall = document.getElementById('callId').value.trim() !== '';
    if (!hasCall) {
      log('Ingresa Call ID si vas a usar WebRTC/KVS.');
    }
    await prepareLocalPreview();
    pollStatus();
    document.getElementById('btnStart').addEventListener('click', startWebRTC);
    document.getElementById('btnHangup').addEventListener('click', hangup);
    document.getElementById('btnRedial').addEventListener('click', redial);
    document.getElementById('btnLeaveMsg').addEventListener('click', onLeaveMsgClick);
  });
  </script>
</body>
</html>
