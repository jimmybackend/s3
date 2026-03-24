<?php
// up.php — Subida multipart reanudable Direct-to-S3 (presigned) con paralelismo.
// - Usa tu Config.php (Config::getS3 y Config::BUCKET).
// - Guarda en s3://<bucket>/Data/uploads/YYYYMMDD/<hash>-<archivo>
// - Sin arrow functions ni <=> (compatibilidad amplia).

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// ===== Metadatos locales (solo JSON, no guardamos el archivo grande) =====
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/Data/uploads');
if (!defined('TMP_DIR'))     define('TMP_DIR', __DIR__ . '/Data/uploads/.tmp');
foreach ([UPLOAD_DIR, TMP_DIR] as $d) { if (!is_dir($d)) { @mkdir($d, 0775, true); } }

// ===== Proveedores: directo desde tu Config.php =====
function cfg_s3() {
    $c = \Config::getS3();
    if (!($c instanceof S3Client)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'Config::getS3() no devolvió Aws\\S3\\S3Client']);
        exit;
    }
    return $c;
}
function cfg_bucket() {
    if (defined('Config::BUCKET')) {
        return constant('Config::BUCKET');
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Config::BUCKET no está definido']);
    exit;
}

// ===== Utilidades reanudación =====
function signature($filename, $filesize) { return sha1($filename.'|'.$filesize); }
function meta_path($sig) { return rtrim(TMP_DIR,'/\\').DIRECTORY_SEPARATOR.$sig.'.json'; }
function save_meta($sig, $data) { @file_put_contents(meta_path($sig), json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
function load_meta($sig) {
    $p = meta_path($sig);
    if (is_file($p)) {
        $j = json_decode((string)@file_get_contents($p), true);
        if (is_array($j)) return $j;
    }
    return null;
}
function list_parts_etags($uploadId, $key) {
    $client = cfg_s3();
    $parts = []; $marker = null;
    try {
        do {
            $args = ['Bucket'=>cfg_bucket(),'Key'=>$key,'UploadId'=>$uploadId];
            if ($marker) $args['PartNumberMarker'] = $marker;
            $res = $client->listParts($args);
            $ps = $res->get('Parts') ?: [];
            foreach ($ps as $p) {
                $num  = (int)($p['PartNumber'] ?? 0);
                $etag = trim((string)($p['ETag'] ?? ''), '"');
                if ($num>0 && $etag!=='') $parts[(string)$num] = $etag;
            }
            $isTrunc = (bool)($res->get('IsTruncated') ?? false);
            $marker  = $res->get('NextPartNumberMarker') ?? null;
        } while ($isTrunc);
    } catch (Throwable $e) {}
    return $parts;
}

// ===== Router AJAX =====
$action = isset($_POST['action']) ? $_POST['action'] : '';
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($action) {
            case 'init':     echo json_encode(handle_init());    break;
            case 'sign':     echo json_encode(handle_sign());    break; // presign URL para un part
            case 'resume':   echo json_encode(handle_resume());  break;
            case 'complete': echo json_encode(handle_complete());break;
            default: http_response_code(400); echo json_encode(['error'=>'Acción no válida']);
        }
    } catch (Throwable $e) {
        http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ===== Acciones =====
function handle_init() {
    $filename = trim((string)($_POST['filename'] ?? ''));
    $filesize = (int)($_POST['filesize'] ?? 0);
    $mime     = trim((string)($_POST['mime'] ?? 'application/octet-stream'));
    if ($filename==='' || $filesize<=0) { http_response_code(400); return ['error'=>'Datos de archivo inválidos']; }

    $sig      = signature($filename, $filesize);
    $basename = preg_replace('/[^\w\-.]+/u', '_', $filename);
    $ymd      = gmdate('Ymd');                 // YYYYMMDD
    $base     = 'Data/uploads';                // prefijo en S3
    $key      = "{$base}/{$ymd}/{$sig}-{$basename}";

    // Marcadores de carpeta (opcionales, para visibilidad en consola)
    $client = cfg_s3();
    $bucket = cfg_bucket();
    foreach (["Data/","{$base}/","{$base}/{$ymd}/"] as $folderKey) {
        try {
            $client->putObject([
                'Bucket'=>$bucket,'Key'=>$folderKey,'Body'=>'','ContentType'=>'application/x-directory'
            ]);
        } catch (Throwable $e) {/* no bloquear */}
    }

    // Crear la subida multipart en S3
    $res = $client->createMultipartUpload([
        'Bucket'      => $bucket,
        'Key'         => $key,
        'ContentType' => $mime,
        'Metadata'    => ['original-name'=>$filename,'original-size'=>(string)$filesize],
    ]);
    $uploadId = (string)$res->get('UploadId');

    save_meta($sig, ['filename'=>$filename,'filesize'=>$filesize,'key'=>$key,'uploadId'=>$uploadId,'parts'=>[]]);

    return ['ok'=>true,'uploadId'=>$uploadId,'key'=>$key,'signature'=>$sig];
}

function handle_sign() {
    // Firma una URL presignada de UploadPart para un partNumber
    $uploadId     = (string)($_POST['uploadId'] ?? '');
    $key          = (string)($_POST['key'] ?? '');
    $partNumber   = (int)($_POST['partNumber'] ?? 0);
    $contentLength= (int)($_POST['contentLength'] ?? 0);

    if ($uploadId==='' || $key==='' || $partNumber<=0 || $contentLength<=0) {
        http_response_code(400); return ['error'=>'Parámetros inválidos para firmar'];
    }

    $client = cfg_s3(); $bucket = cfg_bucket();
    try {
        $cmd = $client->getCommand('UploadPart', [
            'Bucket'       => $bucket,
            'Key'          => $key,
            'UploadId'     => $uploadId,
            'PartNumber'   => $partNumber,
            'ContentLength'=> $contentLength,
        ]);
        // URL válida por 1 hora
        $req = $client->createPresignedRequest($cmd, '+1 hour');
        $url = (string)$req->getUri();
        return ['ok'=>true,'url'=>$url];
    } catch (AwsException $e) {
        http_response_code(500); return ['error'=>'AWS presign UploadPart: '.$e->getAwsErrorMessage()];
    } catch (Throwable $e) {
        http_response_code(500); return ['error'=>$e->getMessage()];
    }
}

function handle_complete() {
    $uploadId = (string)($_POST['uploadId'] ?? '');
    $key      = (string)($_POST['key'] ?? '');
    $etagsJ   = (string)($_POST['etags'] ?? '{}');
    $etags    = json_decode($etagsJ, true) ?: [];
    if ($uploadId==='' || $key==='' || empty($etags)) {
        http_response_code(400); return ['error'=>'Faltan parámetros para completar'];
    }

    $parts = [];
    foreach ($etags as $num=>$tag) {
        $parts[] = ['PartNumber'=>(int)$num, 'ETag'=>'"'.$tag.'"'];
    }
    // Ordenar por PartNumber
    usort($parts, function($a, $b) {
        $pa = isset($a['PartNumber']) ? (int)$a['PartNumber'] : 0;
        $pb = isset($b['PartNumber']) ? (int)$b['PartNumber'] : 0;
        if ($pa === $pb) return 0;
        return ($pa < $pb) ? -1 : 1;
    });

    try {
        $client = cfg_s3(); $bucket = cfg_bucket();
        $res = $client->completeMultipartUpload([
            'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts]
        ]);

        // URL firmada para ver/descargar (1 hora)
        $cmd = $client->getCommand('GetObject', ['Bucket'=>$bucket,'Key'=>$key]);
        $req = $client->createPresignedRequest($cmd, '+1 hour');
        $url = (string)$req->getUri();
    } catch (AwsException $e) {
        http_response_code(500); return ['error'=>'AWS completeMultipartUpload: '.$e->getAwsErrorMessage()];
    } catch (Throwable $e) {
        http_response_code(500); return ['error'=>$e->getMessage()];
    }

    // Limpieza de metadatos locales de esa subida
    foreach (glob(TMP_DIR.'/*.json') as $file) {
        $j = json_decode((string)@file_get_contents($file), true);
        if ($j && is_array($j) && ($j['uploadId'] ?? '') === $uploadId && ($j['key'] ?? '') === $key) { @unlink($file); }
    }

    return [
        'ok'=>true,
        'location'=>(string)($res->get('Location') ?? ''),
        'objectUrl'=>"s3://".cfg_bucket()."/".$key,
        'key'=>$key,
        'url'=>$url
    ];
}

function handle_resume() {
    $filename = trim((string)($_POST['filename'] ?? ''));
    $filesize = (int)($_POST['filesize'] ?? 0);
    $uploadId = (string)($_POST['uploadId'] ?? '');
    $key      = (string)($_POST['key'] ?? '');

    $sig = ($filename && $filesize) ? signature($filename, $filesize) : null;
    $meta = $sig ? load_meta($sig) : null;

    if ($meta) {
        $remote = list_parts_etags($meta['uploadId'], $meta['key']);
        if ($remote) {
            $meta['parts'] = $remote + (isset($meta['parts']) && is_array($meta['parts']) ? $meta['parts'] : []);
            save_meta($sig, $meta);
        }
        return ['found'=>true, 'uploadId'=>$meta['uploadId'], 'key'=>$meta['key'], 'etags'=>($meta['parts'] ?? [])];
    }
    if ($uploadId && $key) {
        $etags = list_parts_etags($uploadId, $key);
        if ($etags) return ['found'=>true,'uploadId'=>$uploadId,'key'=>$key,'etags'=>$etags];
    }
    return ['found'=>false];
}

// ========================== UI ==========================
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>Subida reanudable a S3 (Directo)</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
  :root { --bg:#0b1020; --card:#0f172a; --muted:#9ca3af; --text:#e5e7eb; --accent:#4f46e5; --success:#16a34a; --warn:#f59e0b; --danger:#ef4444; }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Arial}
  .panel{width:min(1200px,95vw);margin:5vh auto;background:linear-gradient(to bottom right,#0c1428,#070b16);
         border:1px solid #1f2937;border-radius:18px;padding:28px 28px 32px;box-shadow:0 10px 40px rgba(0,0,0,.5)}
  h1{margin:0 0 10px;font-weight:700;font-size:28px}
  p.sub{margin:0 0 22px;color:var(--muted);font-size:15px}
  .grid{display:grid;gap:14px;grid-template-columns:1fr}
  @media(min-width:900px){.grid{grid-template-columns:2fr 1fr}}
  .uploader{border:1px dashed #334155;border-radius:16px;padding:24px;display:grid;gap:14px;background:#0b1222}
  .uploader input[type=file]{width:100%;padding:12px;background:#0b1222;color:var(--muted);border:1px solid #1f2937;border-radius:12px;font-size:15px}
  .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
  .btn{appearance:none;border:0;background:var(--accent);color:#fff;padding:12px 18px;border-radius:12px;cursor:pointer;font-weight:700;font-size:15px;transition:transform .06s ease,opacity .2s ease}
  .btn.secondary{background:transparent;color:var(--muted);border:1px solid #273046}
  .btn[disabled]{opacity:.55;cursor:not-allowed}
  .btn:active{transform:translateY(1px)}
  .progress{width:100%;height:12px;background:#0b1020;border-radius:999px;overflow:hidden;border:1px solid #1f2937}
  .bar{height:100%;width:0;background:linear-gradient(90deg,#4f46e5,#22c55e);transition:width .2s ease}
  .status{margin-top:12px;font-size:15px;color:var(--muted);display:grid;gap:6px;max-height:260px;overflow:auto}
  .status .ok{color:var(--success)} .status .warn{color:var(--warn)} .status .err{color:var(--danger)}
  .meta{color:var(--muted);font-size:14px;display:grid;gap:6px}
  code.key{font-family:ui-monospace,Menlo,Consolas,monospace;color:#a78bfa;word-break:break-all}
  .pill{display:inline-block;padding:6px 10px;border-radius:999px;background:#0f172a;border:1px solid #1f2937;color:var(--muted);font-size:13px}
  .result-wrap{width:min(1200px,95vw);margin:18px auto 36px}
  .result{background:#0f172a;border:1px solid #1f2937;border-radius:16px;padding:16px 18px;display:none}
  .result h2{margin:0 0 10px;font-size:18px}
  .result .k{color:#9aa7ff}
</style>
</head>
<body>
<div class="panel">
  <h1>Subida reanudable a S3 (Directo)</h1>
  <p class="sub">Sube en paralelo con paquetes de 15 MB. Mensajes visibles sin diálogos del navegador.</p>

  <div class="grid">
    <div class="uploader">
      <input id="file" type="file" />
      <div class="row">
        <button class="btn" id="startBtn">Iniciar subida</button>
        <button class="btn secondary" id="pauseBtn" disabled>Pausar</button>
        <button class="btn secondary" id="resumeBtn" disabled>Reanudar</button>
        <span class="pill" id="chunkInfo">Tamaño de paquete: 15 MB</span>
      </div>

      <div class="progress"><div class="bar" id="bar"></div></div>
      <div class="status" id="status"></div>
    </div>

    <div>
      <div class="meta">
        <div><strong>Archivo:</strong> <span id="fn">—</span></div>
        <div><strong>Peso:</strong> <span id="fs">—</span></div>
        <div><strong>UploadId:</strong> <code class="key" id="uploadId">—</code></div>
        <div><strong>Objeto S3:</strong> <code class="key" id="s3key">—</code></div>
      </div>
    </div>
  </div>
</div>

<div class="result-wrap">
  <div class="result" id="result">
    <h2>Resultado</h2>
    <div><span class="k">Objeto S3:</span> <code class="key" id="resKey">—</code></div>
    <div><span class="k">Ruta:</span> <code class="key" id="resUrl">—</code></div>
    <div id="resLinkWrap" style="margin-top:8px;display:none;">
      <a id="resLink" href="#" target="_blank" style="text-decoration:none;border:1px solid #334155;padding:8px 10px;border-radius:10px;color:#e5e7eb;">Abrir enlace temporal</a>
    </div>
  </div>
</div>

<script>
(() => {
  const $ = (id) => document.getElementById(id);
  const fileInput = $('file'), startBtn=$('startBtn'), pauseBtn=$('pauseBtn'), resumeBtn=$('resumeBtn');
  const bar=$('bar'), status=$('status'), fn=$('fn'), fs=$('fs'), uploadIdEl=$('uploadId'), s3keyEl=$('s3key');
  const resultBox=$('result'), resKey=$('resKey'), resUrl=$('resUrl'), resLink=$('resLink'), resLinkWrap=$('resLinkWrap');

  // === Config: 15MB y 4 workers en paralelo ===
  const CHUNK_SIZE = 15 * 1024 * 1024; // 15 MB (S3 permite >= 5 MB en multipart)
  const MAX_WORKERS = 4;

  let state = {
    paused:false, file:null, uploadId:null, s3key:null, etags:{},
    totalParts:0, nextPart:1, inFlight:0, uploadedBytes:0, aborters:new Map()
  };

  const humanSize = (n)=>{const u=['B','KB','MB','GB','TB'];let i=0,v=n;while(v>1024&&i<u.length-1){v/=1024;i++;}return `${v.toFixed(1)} ${u[i]}`;};
  const msg = (t,c='')=>{const d=document.createElement('div'); if(c)d.classList.add(c); d.textContent=t; status.appendChild(d); status.scrollTop=status.scrollHeight;};
  const setProgress=(p)=>{ bar.style.width = `${p}%`; };

  async function postForm(action, data, signal) {
    const form=new FormData(); form.append('action',action); for (const [k,v] of Object.entries(data)) if(v!==undefined&&v!==null) form.append(k,v);
    const res=await fetch(location.pathname,{method:'POST',body:form,signal});
    if(!res.ok){ const t=await res.text().catch(()=> ''); throw new Error(`HTTP ${res.status}: ${t || res.statusText}`); }
    return res.json();
  }

  function calcNextMissingPart(et){const nums=Object.keys(et).map(n=>+n).sort((a,b)=>a-b); let e=1; for(const n of nums){ if(n!==e) return e; e++; } return e; }

  async function initUpload(){
    state.file=fileInput.files?.[0];
    if(!state.file){ msg('Selecciona un archivo para comenzar.','warn'); return; }
    fn.textContent=state.file.name; fs.textContent=humanSize(state.file.size);

    msg('Verificando sesión previa…');
    let data={found:false};
    try { data = await postForm('resume',{filename:state.file.name,filesize:state.file.size}); }
    catch(e){ msg(`No se pudo verificar sesión previa, se iniciará una nueva. Detalle: ${e.message}`,'warn'); }

    if(data && data.found){
      state.uploadId=data.uploadId; state.s3key=data.key; state.etags=data.etags||{};
      msg('Sesión previa encontrada. Sincronizando…','ok');
    } else {
      try{
        const init=await postForm('init',{filename:state.file.name,filesize:state.file.size,mime:state.file.type||'application/octet-stream'});
        state.uploadId=init.uploadId; state.s3key=init.key; state.etags={};
        msg('Sesión creada en S3.','ok');
      }catch(e){ msg(`No se pudo iniciar la subida: ${e.message}`,'err'); return; }
    }

    uploadIdEl.textContent=state.uploadId||'—'; s3keyEl.textContent=state.s3key||'—';

    state.totalParts=Math.max(1, Math.ceil(state.file.size/CHUNK_SIZE));
    // Calcular bytes ya subidos según ETags conocidos
    let uploadedParts = Object.keys(state.etags).map(n=>+n).filter(n=>n>0);
    let uploadedBytes = 0;
    for(const pn of uploadedParts){ const end=Math.min(state.file.size, pn*CHUNK_SIZE); const start=(pn-1)*CHUNK_SIZE; uploadedBytes += Math.max(0, end-start); }
    state.uploadedBytes = uploadedBytes;
    state.nextPart = calcNextMissingPart(state.etags);
    setProgress(Math.min(100,(uploadedBytes/state.file.size)*100));

    startBtn.disabled=true; pauseBtn.disabled=false; resumeBtn.disabled=true;
    state.paused=false;
    scheduleWorkers();
  }

  function scheduleWorkers(){
    while(!state.paused && state.inFlight < MAX_WORKERS && state.nextPart <= state.totalParts){
      const partNumber = state.nextPart++;
      uploadPartParallel(partNumber).catch(e=>{
        msg(`Error en paquete ${partNumber}: ${e.message}`,'err');
        state.paused=true;
        pauseBtn.disabled=true; resumeBtn.disabled=false;
      });
    }
    if (!state.paused && state.inFlight===0 && state.nextPart>state.totalParts) {
      // Todos los parts enviados: completar
      completeUpload();
    }
  }

  async function signPart(partNumber, size, signal) {
    const r = await postForm('sign',{uploadId:state.uploadId,key:state.s3key,partNumber,contentLength:size},signal);
    if(!r.ok || !r.url) throw new Error(r.error || 'Fallo al firmar URL');
    return r.url;
  }

  async function uploadPartParallel(partNumber){
    const f=state.file;
    const start=(partNumber-1)*CHUNK_SIZE, end=Math.min(start+CHUNK_SIZE,f.size), blob=f.slice(start,end);
    msg(`Subiendo paquete ${partNumber}/${state.totalParts}…`);
    const controller = new AbortController();
    state.aborters.set(partNumber, controller);
    state.inFlight++;

    try {
      const url = await signPart(partNumber, blob.size, controller.signal);

      // PUT directo a S3 con la parte
      const res = await fetch(url, {
        method: 'PUT',
        body: blob,
        headers: { 'Content-Length': String(blob.size) },
        signal: controller.signal
      });
      if(!res.ok){
        const t=await res.text().catch(()=> ''); throw new Error(`PUT ${res.status}: ${t || res.statusText}`);
      }

      // Tomar ETag del header (asegúrate que CORS exponga ETag)
      let etag = res.headers.get('ETag') || res.headers.get('etag') || '';
      etag = etag.replace(/^"+|"+$/g,''); // quitar comillas

      if(!etag){
        // Si ETag no viene por CORS, podemos re-consultar al servidor listParts…
        const sync = await postForm('resume',{uploadId:state.uploadId,key:state.s3key});
        if (sync && sync.found && sync.etags && sync.etags[String(partNumber)]) {
          etag = sync.etags[String(partNumber)];
        } else {
          throw new Error('No se pudo obtener ETag (revisa CORS del bucket: ExposeHeaders: ETag)');
        }
      }

      state.etags[partNumber]=etag;
      state.uploadedBytes += (end-start);
      setProgress(Math.min(100,(state.uploadedBytes/f.size)*100));
      msg(`Paquete ${partNumber} confirmado (${( (end-start)/1024/1024 ).toFixed(1)} MB).`,'ok');

    } finally {
      state.inFlight--;
      state.aborters.delete(partNumber);
      // Programar más trabajos si hay pendientes
      if(!state.paused) scheduleWorkers();
    }
  }

  async function completeUpload(){
    msg('Completando subida en S3…');
    try{
      const r=await postForm('complete',{uploadId:state.uploadId,key:state.s3key,etags:JSON.stringify(state.etags)});
      setProgress(100);
      msg(`Subida finalizada. Objeto: ${r.objectUrl || state.s3key}`,'ok');

      // Bloque Resultado
      const objectUrl = r.objectUrl || ('s3://' + (state.s3key || ''));
      resKey.textContent = r.key || state.s3key || '—';
      resUrl.textContent = objectUrl;
      if (r.url) { resLink.href = r.url; resLinkWrap.style.display='block'; }
      resultBox.style.display='block';

      pauseBtn.disabled=true; resumeBtn.disabled=true;
    }catch(e){
      msg(`Error al completar: ${e.message}`,'err');
      pauseBtn.disabled=true; resumeBtn.disabled=false;
    }
  }

  startBtn.addEventListener('click', initUpload);
  pauseBtn.addEventListener('click',()=>{
    state.paused=true;
    for (const c of state.aborters.values()) { try{ c.abort(); }catch(_e){} }
    state.aborters.clear();
    pauseBtn.disabled=true; resumeBtn.disabled=false; msg('Subida pausada.','warn');
  });
  resumeBtn.addEventListener('click', async ()=>{
    if(!state.file || !state.uploadId){ msg('No hay sesión para reanudar.','warn'); return; }
    state.paused=false; resumeBtn.disabled=true; pauseBtn.disabled=false;
    try{
      const r=await postForm('resume',{filename:state.file.name,filesize:state.file.size,uploadId:state.uploadId,key:state.s3key});
      if(r.found){
        state.etags=r.etags||state.etags;
        // recalcular bytes subidos
        let uploadedBytes=0;
        for(const pn of Object.keys(state.etags)){ const n=+pn; if(!n) continue; const end=Math.min(state.file.size, n*CHUNK_SIZE); const start=(n-1)*CHUNK_SIZE; uploadedBytes += Math.max(0,end-start); }
        state.uploadedBytes = uploadedBytes;
        setProgress(Math.min(100,(uploadedBytes/state.file.size)*100));
        // reprogramar a partir del siguiente faltante
        const nums=Object.keys(state.etags).map(n=>+n).sort((a,b)=>a-b);
        let e=1; for(const n of nums){ if(n!==e) break; e++; }
        state.nextPart = e;
        scheduleWorkers();
        msg(`Sesión sincronizada. Continuando en el paquete ${state.nextPart}.`,'ok');
      } else {
        msg('No se encontró sesión previa.','warn');
      }
    }catch(e){ msg(`No se pudo sincronizar: ${e.message}`,'warn'); }
  });
})();
</script>
</body>
</html>
