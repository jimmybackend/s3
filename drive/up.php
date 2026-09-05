<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Upload\PublicMultipartUploadService;

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();

$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');

$actorUserId = $session->userId();
$actorRole = trim((string)($_SESSION['role'] ?? ''));

if (!in_array($actorRole, ['Administración', 'Soporte'], true)) {
    http_response_code(403);
    exit('No autorizado.');
}

$targetUserId = (int)(
    $_POST['target_user_id']
    ?? $_GET['target_user_id']
    ?? 0
);

$adminUpload = $app->adminMultipartUploadService();
$targetUser = $targetUserId > 0
    ? $adminUpload->targetUser($targetUserId)
    : null;
$users = $adminUpload->users();

$action = trim((string)($_POST['action'] ?? ''));
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($targetUser === null) {
            throw new RuntimeException('Debes seleccionar el usuario destino.');
        }

        $result = $adminUpload->handle(
            $actorUserId,
            $targetUserId,
            $action,
            $_POST,
            $_FILES
        );

        echo json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(
            ['error' => $error->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
    exit;
}

// ========================== UI ==========================
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>Subida reanudable a S3 (Directo)</title>
<link rel="icon" href="ellogo.png" type="image/png">
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

<?php if ($targetUser === null): ?>

<div class="panel">
  <h1>Seleccionar usuario</h1>

  <p class="sub">
    Elige a qué usuario deseas subir los archivos.
  </p>

  <div style="display:grid;gap:10px;">
    <?php foreach ($users as $user): ?>
      <a
        href="?target_user_id=<?= (int)$user['id'] ?>"
        style="
          display:block;
          padding:14px 16px;
          border:1px solid #334155;
          border-radius:12px;
          color:#e5e7eb;
          text-decoration:none;
          background:#0b1222;
        "
      >
        <?= htmlspecialchars((string)$user['email']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

</body>
</html>
<?php exit; ?>

<?php endif; ?>

<div class="panel">

  <div style="margin-bottom:15px;">
    <span class="pill">
      Usuario destino:
      <strong>
        <?= htmlspecialchars((string)$targetUser['email']) ?>
      </strong>
    </span>

    <a
      href="up.php"
      style="margin-left:10px;color:#9aa7ff;"
    >
      Cambiar usuario
    </a>
  </div>
  <h1>Subida reanudable a S3 (Directo)</h1>
  <p class="sub">
    Subida multipart directa a Amazon S3.
    PHP únicamente autoriza y registra la operación;
    los datos del archivo viajan directamente desde tu navegador a S3.
  </p>

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

  const TARGET_USER_ID =
    <?= (int)$targetUserId ?>;
  const fileInput = $('file'), startBtn=$('startBtn'), pauseBtn=$('pauseBtn'), resumeBtn=$('resumeBtn');
  const bar=$('bar'), status=$('status'), fn=$('fn'), fs=$('fs'), uploadIdEl=$('uploadId'), s3keyEl=$('s3key');
  const resultBox=$('result'), resKey=$('resKey'), resUrl=$('resUrl'), resLink=$('resLink'), resLinkWrap=$('resLinkWrap');

  // =====================================================
  // MULTIPART DIRECT-TO-S3
  // =====================================================
  // Los archivos NO atraviesan PHP.
  // PHP solo crea el multipart, firma partes y completa.
  let CHUNK_SIZE = 32 * 1024 * 1024;

  // Dos conexiones en móvil y tres en escritorio.
  const MAX_WORKERS =
    /Android|iPhone|iPad|Mobile/i.test(navigator.userAgent)
      ? 2
      : 3;

  function elegirChunk(file) {
    const connection =
      navigator.connection ||
      navigator.mozConnection ||
      navigator.webkitConnection ||
      null;

    const type =
      String(connection?.effectiveType || '').toLowerCase();

    const downlink =
      Number(connection?.downlink || 0);

    /*
     * Ya no estamos limitados por upload_max_filesize/post_max_size,
     * porque el chunk no atraviesa PHP.
     *
     * Valores conservadores pero rápidos:
     *
     * lenta       -> 8 MB
     * 3G          -> 16 MB
     * normal      -> 32 MB
     * rápida      -> 64 MB
     * muy rápida  -> 128 MB
     */
    let mb = 32;

    if (
      type === 'slow-2g' ||
      type === '2g'
    ) {
      mb = 8;

    } else if (
      type === '3g' ||
      (downlink > 0 && downlink < 5)
    ) {
      mb = 16;

    } else if (downlink >= 50) {
      mb = 128;

    } else if (
      downlink >= 10 ||
      type === '4g'
    ) {
      mb = 64;
    }

    /*
     * Para archivos pequeños no necesitamos bloques enormes.
     */
    if (file && file.size < 100 * 1024 * 1024) {
      mb = Math.min(mb, 16);
    }

    /*
     * S3 multipart admite hasta 10,000 partes.
     * Dejamos margen y aumentamos automáticamente el chunk
     * cuando el archivo sea extraordinariamente grande.
     */
    if (file && file.size > 0) {
      const MB = 1024 * 1024;

      const minimumByParts =
        Math.ceil(
          file.size /
          9900 /
          MB
        );

      mb = Math.max(
        mb,
        minimumByParts
      );
    }

    /*
     * Límite práctico del uploader web.
     */
    mb = Math.max(8, Math.min(256, mb));

    return mb * 1024 * 1024;
  }

  // Si una conexión directa navegador -> S3 falla temporalmente,
  // reintentamos el mismo paquete antes de pausar la subida.
  const MAX_RETRIES = 5;
  const RETRY_BASE_MS = 1500;

  const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

  let state = {
    paused:false, failed:false, file:null, uploadId:null, s3key:null, etags:{},
    totalParts:0,
    nextPart:1,
    inFlight:0,
    uploadedBytes:0,
    aborters:new Map(),
    progressBytes:new Map()
  };

  const humanSize = (n)=>{const u=['B','KB','MB','GB','TB'];let i=0,v=n;while(v>1024&&i<u.length-1){v/=1024;i++;}return `${v.toFixed(1)} ${u[i]}`;};
  const msg = (t,c='')=>{const d=document.createElement('div'); if(c)d.classList.add(c); d.textContent=t; status.appendChild(d); status.scrollTop=status.scrollHeight;};
  const setProgress=(p)=>{
    bar.style.width = `${Math.max(0, Math.min(100, p))}%`;
  };

  function actualizarProgresoDirecto() {
    if (!state.file || !state.file.size) {
      setProgress(0);
      return;
    }

    let inFlightBytes = 0;

    for (const bytes of state.progressBytes.values()) {
      inFlightBytes += Number(bytes || 0);
    }

    const total =
      Math.min(
        state.file.size,
        state.uploadedBytes + inFlightBytes
      );

    setProgress(
      (total / state.file.size) * 100
    );
  }

  async function postForm(action, data, signal) {
    const form=new FormData();

    form.append('action', action);
    form.append('target_user_id', String(TARGET_USER_ID));

    for (const [k,v] of Object.entries(data)) {
      if(v!==undefined && v!==null) {
        form.append(k,v);
      }
    }
    const res=await fetch(location.pathname,{method:'POST',body:form,signal});
    if(!res.ok){ const t=await res.text().catch(()=> ''); throw new Error(`HTTP ${res.status}: ${t || res.statusText}`); }
    return res.json();
  }

  function calcNextMissingPart(et){const nums=Object.keys(et).map(n=>+n).sort((a,b)=>a-b); let e=1; for(const n of nums){ if(n!==e) return e; e++; } return e; }

  async function initUpload(){
    state.file=fileInput.files?.[0];
    state.failed=false;
    if(!state.file){ msg('Selecciona un archivo para comenzar.','warn'); return; }
    fn.textContent=state.file.name;
    fs.textContent=humanSize(state.file.size);

    CHUNK_SIZE =
      elegirChunk(state.file);

    const chunkInfo =
      document.getElementById('chunkInfo');

    if (chunkInfo) {
      chunkInfo.textContent =
        'Directo a S3 · ' +
        humanSize(CHUNK_SIZE) +
        ' · ' +
        MAX_WORKERS +
        (MAX_WORKERS === 1 ? ' conexión' : ' conexiones');
    }

    msg('Verificando sesión previa…');
    let data={found:false};
    try { data = await postForm('resume',{filename:state.file.name,filesize:state.file.size}); }
    catch(e){ msg(`No se pudo verificar sesión previa, se iniciará una nueva. Detalle: ${e.message}`,'warn'); }

    if(data && data.found){

      if (data.chunk_size) {
        CHUNK_SIZE =
          Number(data.chunk_size);
      }

      state.uploadId=data.uploadId; state.s3key=data.key; state.etags=data.etags||{};
      msg('Sesión previa encontrada. Sincronizando…','ok');
    } else {
      try{
        const init=await postForm('init',{
          filename:state.file.name,
          filesize:state.file.size,
          mime:state.file.type||'application/octet-stream',
          chunk_size:CHUNK_SIZE
        });

        if (init.chunk_size) {
          CHUNK_SIZE =
            Number(init.chunk_size);
        }
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
        const aborted =
          e && (
            e.name === 'AbortError' ||
            String(e.message || '').toLowerCase().includes('abort')
          );

        // Pausar manualmente no debe marcar la sesión como fallida.
        if (state.paused && aborted) {
          return;
        }

        msg(`Error definitivo en paquete ${partNumber}: ${e.message}`,'err');
        state.failed=true;
        state.paused=true;
        pauseBtn.disabled=true;
        resumeBtn.disabled=false;
      });
    }
    if (!state.paused && !state.failed && state.inFlight===0 && state.nextPart>state.totalParts) {
      if (Object.keys(state.etags).length >= state.totalParts) {
        completeUpload();
      } else {
        msg('No se completa: faltan paquetes confirmados.', 'err');
      }
    }
  }

  async function solicitarUrlParte(partNumber, signal) {
    const signed = await postForm(
      'sign',
      {
        uploadId: state.uploadId,
        key: state.s3key,
        partNumber: partNumber
      },
      signal
    );

    if (!signed || !signed.url) {
      throw new Error(
        `No se pudo obtener URL presignada para la parte ${partNumber}.`
      );
    }

    return signed.url;
  }

  function putDirectoS3(
    url,
    blob,
    partNumber,
    signal
  ) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();

      xhr.open(
        'PUT',
        url,
        true
      );

      xhr.timeout = 0;

      xhr.upload.onprogress = function (event) {
        if (!event.lengthComputable) {
          return;
        }

        state.progressBytes.set(
          partNumber,
          event.loaded
        );

        actualizarProgresoDirecto();
      };

      xhr.onload = function () {
        state.progressBytes.delete(partNumber);

        if (
          xhr.status < 200 ||
          xhr.status >= 300
        ) {
          reject(
            new Error(
              `S3 respondió HTTP ${xhr.status} en la parte ${partNumber}.`
            )
          );
          return;
        }

        const etag =
          String(
            xhr.getResponseHeader('ETag') || ''
          )
          .replace(/^"|"$/g, '')
          .trim();

        if (!etag) {
          reject(
            new Error(
              'S3 recibió la parte pero no expuso ETag. ' +
              'Verifica ExposeHeaders: ETag en CORS.'
            )
          );
          return;
        }

        resolve(etag);
      };

      xhr.onerror = function () {
        state.progressBytes.delete(partNumber);
        actualizarProgresoDirecto();

        reject(
          new Error(
            `Error de red enviando directamente a S3 la parte ${partNumber}.`
          )
        );
      };

      xhr.onabort = function () {
        state.progressBytes.delete(partNumber);
        actualizarProgresoDirecto();

        const error = new Error(
          `Parte ${partNumber} cancelada.`
        );

        error.name = 'AbortError';

        reject(error);
      };

      if (signal) {
        if (signal.aborted) {
          xhr.abort();
          return;
        }

        signal.addEventListener(
          'abort',
          () => {
            try {
              xhr.abort();
            } catch (_) {}
          },
          { once: true }
        );
      }

      xhr.send(blob);
    });
  }

  async function uploadPartDirecto(
    partNumber,
    blob,
    signal
  ) {
    /*
     * Pedimos una URL nueva en cada intento.
     * La petición a PHP pesa apenas unos bytes.
     */
    const url = await solicitarUrlParte(
      partNumber,
      signal
    );

    return putDirectoS3(
      url,
      blob,
      partNumber,
      signal
    );
  }

  async function uploadPartParallel(partNumber){
    const f=state.file;
    const start=(partNumber-1)*CHUNK_SIZE, end=Math.min(start+CHUNK_SIZE,f.size), blob=f.slice(start,end);
    msg(`Subiendo paquete ${partNumber}/${state.totalParts}…`);
    const controller = new AbortController();
    state.aborters.set(partNumber, controller);
    state.inFlight++;

    try {
      // Direct-to-S3 real: PHP solo genera la URL presignada.
      // El mismo paquete puede reintentarse sin reiniciar el multipart.
      let etag = '';
      let lastError = null;

      for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
        try {
          etag = await uploadPartDirecto(
            partNumber,
            blob,
            controller.signal
          );

          if (etag) {
            if (attempt > 1) {
              msg(
                `Paquete ${partNumber}: recuperado correctamente en intento ${attempt}/${MAX_RETRIES}.`,
                'ok'
              );
            }
            break;
          }

        } catch (e) {
          lastError = e;

          if (controller.signal.aborted) {
            throw e;
          }

          if (attempt < MAX_RETRIES) {
            const delay = RETRY_BASE_MS * attempt;

            msg(
              `Paquete ${partNumber}: intento ${attempt}/${MAX_RETRIES} falló. Reintentando en ${(delay / 1000).toFixed(1)} s…`,
              'warn'
            );

            await sleep(delay);
          }
        }
      }

      if (!etag) {
        throw lastError || new Error(
          `No se pudo confirmar el paquete ${partNumber} después de ${MAX_RETRIES} intentos.`
        );
      }

      state.progressBytes.delete(partNumber);
      state.etags[partNumber] = etag;
      state.uploadedBytes += (end - start);
      actualizarProgresoDirecto();
      msg(`Paquete ${partNumber} confirmado (${( (end-start)/1024/1024 ).toFixed(1)} MB).`,'ok');

    } finally {
      state.inFlight--;
      state.aborters.delete(partNumber);
      state.progressBytes.delete(partNumber);
      // Programar más trabajos si hay pendientes
      if(!state.paused && !state.failed) scheduleWorkers();
    }
  }

  async function completeUpload(){
    msg('Completando subida en S3…');
    try{
      const r=await postForm('complete',{
        uploadId:state.uploadId,
        key:state.s3key,
        etags:JSON.stringify(state.etags),
        filename:state.file.name,
        filesize:state.file.size
      });
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