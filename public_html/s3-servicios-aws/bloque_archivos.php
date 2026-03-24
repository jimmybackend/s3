<?php
/*  bloque_archivos.php — v3.3
    - Si el archivo está seguro y bloqueado, solo se muestran acciones de seguridad
    - Si está normal o desbloqueado, se muestran todas las demás acciones
    - No expone data-original cuando está seguro y bloqueado
    - Mantiene tu estructura original
*/

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function bytes_human($bytes){
  $bytes = (int)$bytes;
  if ($bytes < 1024)       return $bytes . ' B';
  if ($bytes < 1048576)    return round($bytes/1024,2) . ' KB';
  if ($bytes < 1073741824) return round($bytes/1048576,2) . ' MB';
  return round($bytes/1073741824,2) . ' GB';
}
function ext_de($nombre){ return strtolower(pathinfo($nombre, PATHINFO_EXTENSION)); }
function build_file_s3_key(string $ruta, string $encriptado): string {
  $ruta = rtrim(str_replace('\\', '/', trim($ruta)), '/') . '/';
  $enc  = ltrim(str_replace('\\', '/', trim($encriptado)), '/');
  if ($enc === '') return '';
  if (strpos($enc, $ruta) === 0) return $enc;
  return $ruta . $enc;
}
function registro_encriptado(array $row){ return ($row['Nombre'] ?? '') !== ($row['Encriptado'] ?? ''); }
function registro_bloqueado(array $row): bool {
  return (($row['AccessType'] ?? 'normal') === 'secure');
}

function registro_tiene_seguridad(array $row): bool {
  $a = (string)($row['AccessType'] ?? 'normal');
  $p = (string)($row['PasswordHash'] ?? '');
  return in_array($a, ['secure', 'unlocked'], true) || $p !== '';
}
function tooltip_from_metadatos(?string $raw): string {
  if (!$raw) return 'Sin metadatos';
  $raw = trim($raw);
  $decoded = json_decode($raw, true);
  $pretty = (json_last_error() === JSON_ERROR_NONE)
    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : $raw;
  if (mb_strlen($pretty) > 2000) $pretty = mb_substr($pretty, 0, 2000) . "…";
  $pretty = str_replace(["\r\n","\r","\n"], "&#10;", htmlspecialchars($pretty, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'));
  return $pretty;
}

/* ---------------- Parámetros (sin formulario aquí) ---------------- */
$rutaGet = trim((string)($_GET['ruta'] ?? ''));
if ($rutaGet !== '') {
  $_SESSION['ruta_actual'] = $rutaGet;
}

$rutaActual   = $_SESSION['ruta_actual'] ?? 'Data/';
$rutaActual   = rtrim(str_replace('\\', '/', $rutaActual), '/') . '/';
$buscar       = $_GET['buscar'] ?? '';
$tipo         = $_GET['tipo'] ?? '';
$fechaInicio  = $_GET['fecha_inicio'] ?? '';
$fechaFin     = $_GET['fecha_fin'] ?? '';
$pagina       = max(1, intval($_GET['pagina'] ?? 1));
$limite       = max(5, intval($_GET['limite'] ?? 5));

/* ---------------- WHERE base ---------------- */
$where  = "Ruta = ? AND Found = 1";
$types  = "s";
$params = [$rutaActual];

if ($buscar !== '') {
  $where .= " AND (Nombre LIKE CONCAT('%',?,'%') OR Encriptado LIKE CONCAT('%',?,'%'))";
  $types .= "ss"; $params[] = $buscar; $params[] = $buscar;
}
if ($tipo !== '') {
  $where .= " AND LOWER(SUBSTRING_INDEX(Nombre,'.',-1)) = ?";
  $types .= "s"; $params[] = strtolower($tipo);
}
if ($fechaInicio !== '') {
  $where .= " AND DATE(Fecha) >= ?";
  $types .= "s"; $params[] = $fechaInicio;
}
if ($fechaFin !== '') {
  $where .= " AND DATE(Fecha) <= ?";
  $types .= "s"; $params[] = $fechaFin;
}

/* ---------------- Total (filtrado actual) ---------------- */
$sqlCount = "SELECT COUNT(*) as n FROM FileS3 WHERE $where";
$stmtC = $db_connection->prepare($sqlCount);
$stmtC->bind_param($types, ...$params);
$stmtC->execute();
$resC = $stmtC->get_result();
$total = ($resC && ($r=$resC->fetch_assoc())) ? (int)$r['n'] : 0;
$stmtC->close();

/* ---------------- Totales carpeta (sin filtros) ---------------- */
$sqlFolder = "SELECT COUNT(*) as n, COALESCE(SUM(Tamano),0) as s FROM FileS3 WHERE Ruta = ? AND Found = 1";
$stmtF = $db_connection->prepare($sqlFolder);
$stmtF->bind_param("s", $rutaActual);
$stmtF->execute();
$resF = $stmtF->get_result();
$carpetaTotal = 0;
$carpetaPesoMB = 0.0;
if ($resF && ($rf = $resF->fetch_assoc())) {
  $carpetaTotal = (int)$rf['n'];
  $carpetaPesoMB = round(((int)$rf['s']) / 1048576, 2);
}
$stmtF->close();

$offset = ($pagina - 1) * $limite;

/* ---------------- Página de datos ---------------- */
$sql = "SELECT id_, Nombre, Encriptado, Tamano, Metadatos, Ruta,
               Found, AccessType, PasswordHash, SecureHint, SecureUpdatedAt, Fecha, user_id_
        FROM FileS3
        WHERE $where
        ORDER BY Fecha DESC
        LIMIT ? OFFSET ?";
$typesPage  = $types . "ii";
$paramsPage = array_merge($params, [$limite, $offset]);

$stmt = $db_connection->prepare($sql);
$stmt->bind_param($typesPage, ...$paramsPage);
$stmt->execute();
$result = $stmt->get_result();

$filas = [];
while ($row = $result->fetch_assoc()) $filas[] = $row;
$stmt->close();

/* ---------------- Clasificación por tipo ---------------- */
$imagenesExt = ['jpg','jpeg','png','gif','webp','bmp'];
$audioExt    = ['mp3','wav','ogg','opus','m4a','aac'];
$videoExt    = ['mp4','webm','mov','avi','mkv'];
$txtEditExt  = ['txt','srt','vtt','md','html','css','js','php','py','json','csv','sql','jas'];
$textractExt = ['jpg','jpeg','png','tif','tiff','pdf'];
$traducirExt = ['txt','pdf','jpg','jpeg','png','tif','tiff'];
$analizarExt = ['jpg','jpeg','png','tif','tiff','bmp'];

/* ---------------- Preparar datos para galería y conteos (página) ---------------- */
$imagenesPagina = [];
$visibles = 0;
$noVisibles = 0;
$segurosAbiertos = 0;

foreach ($filas as $r) {
  $ext = ext_de($r['Nombre']);

  if (in_array($ext, $imagenesExt, true)) {
    $s3key = build_file_s3_key(
      (string)($r['Ruta'] ?? ''),
      (string)($r['Encriptado'] ?? '')
    );

    $imagenesPagina[] = [
      'key'    => $s3key,
      'nombre' => $r['Nombre']
    ];
  }

  if (registro_bloqueado($r)) {
    $noVisibles++;
  } else {
    $visibles++;

    if (registro_tiene_seguridad($r)) {
      $segurosAbiertos++;
    }
  }
}
?>

<div class="archivos-wrap is-loading" id="archivosWrap">

  <!-- Contexto persistente para JS -->
  <div id="archivosContexto"
       data-ruta-actual="<?= h($rutaActual) ?>"
       data-pagina-actual="<?= (int)$pagina ?>"
       data-limite="<?= (int)$limite ?>"></div>
  <script type="application/json" id="imagenesGaleriaData"><?= json_encode($imagenesPagina, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
  <!-- Loader -->
  <div id="archivosLoaderBackdrop"></div>
  <div id="archivosLoaderOverlay">
    <span class="spinner"></span>
    <span id="archivosLoaderText">Cargando…</span>
  </div>
  


  <!-- ====== Resumen carpeta ====== -->
  
  <div class="resumen-carpeta">
<div class="small">
  Archivos: <strong><?= (int)$carpetaTotal ?></strong> |
  Peso: <strong><?= number_format($carpetaPesoMB, 2) ?></strong> MB |
  Ruta: <code><?= h($rutaActual) ?></code> |
  Visibles (página): <strong><?= (int)$visibles ?></strong> |
  Bloqueados (página): <strong><?= (int)$noVisibles ?></strong> |
  Protegidos abiertos (página): <strong><?= (int)$segurosAbiertos ?></strong> |
  Filtrados (total): <strong><?= (int)$total ?></strong>
</div>
  </div>
  
    <!-- ====== LISTA ====== -->
  <div class="mb-2 d-flex align-items-center gap-2">
    <label class="mb-0">
      <!-- <input type="checkbox" id="selectAll">-->
      <input type="checkbox" id="checkAllFiles">
      Seleccionar todos
    </label>
    <span id="filesSelectedCount" class="text-muted small"></span>
    <button type="button" class="btn btn-sm btn-danger" onclick="deleteSelected()">Eliminar seleccionados</button>
    <button type="button" class="btn btn-sm btn-primary" onclick="downloadSelected()">Descargar seleccionados</button>
    <button type="button" class="btn btn-sm btn-secondary" onclick="moveSelected()">Mover seleccionados</button>
    <button type="button" id="btnVerGaleria" class="btn btn-sm btn-outline-primary <?= count($imagenesPagina) ? '' : 'd-none' ?>" data-toggle="modal" data-target="#modalGaleriaCompleta"><i class="fas fa-images"></i></button>
  </div>


  <ul class="list-group">
    <?php if (!$filas): ?>
      <li class="list-group-item">No hay archivos.</li>
    <?php else: ?>
    <?php
    $videoIndex = -1;
    foreach ($filas as $row):
      $nombre   = $row['Nombre'];
      $rutaRow  = $row['Ruta'];
      $keyEnc   = $row['Encriptado'];

      $uid    = (int)($row['user_id_'] ?? 1);
      $s3key  = build_file_s3_key((string)$rutaRow, (string)$keyEnc);
      $s3keyQ = rawurlencode($s3key);

      $thumbUrl = "thumb.php?key={$s3keyQ}&uid={$uid}&w=128&h=128";
      $origUrl  = "ver_archivo.php?archivo={$s3keyQ}";
      $placeholder = "img/loading.gif";

      $tamano    = (int)($row['Tamano'] ?? 0);
      $fechaTxt  = date('Y-m-d H:i', strtotime($row['Fecha'] ?? 'now'));
      $ext       = ext_de($nombre);
      $metaTitle = tooltip_from_metadatos($row['Metadatos'] ?? null);

      $esImg   = in_array($ext, $imagenesExt, true);
      $esAudio = in_array($ext, $audioExt, true);
      $esVideo = in_array($ext, $videoExt, true);
      $editTxt = in_array($ext, $txtEditExt, true);
      $puedeTex= in_array($ext, $textractExt, true);
      $puedeTrd= in_array($ext, $traducirExt, true);
      $puedeAna= in_array($ext, $analizarExt, true);

      $yaEncript = registro_encriptado($row);
        $bloqueado = registro_bloqueado($row);
        $seguro    = registro_tiene_seguridad($row);
        $unlocked  = (($row['AccessType'] ?? 'normal') === 'unlocked');
        $soloSeguridad = $bloqueado;

      $rid = substr(md5($s3key),0,10);
      if ($esVideo) $videoIndex++;

      $clsEnc = $yaEncript ? 'enc-yes' : 'enc-no';
      $clsSec = $seguro    ? 'sec-yes' : 'sec-no';
    ?>
     <li
  class="list-group-item d-flex justify-content-between align-items-center file-row <?= $esAudio ? 'file-row-audio' : '' ?> <?= $clsEnc ?> <?= $clsSec ?> is-pending"
        data-secure="<?= $seguro ? '1' : '0' ?>"
        data-unlocked="<?= $unlocked ? '1' : '0' ?>"
        data-secure-hint="<?= h((string)($row['SecureHint'] ?? '')) ?>"
        data-tipo="archivo"
        data-ext="<?= h($ext) ?>"
        data-key="<?= h($s3key) ?>"
        data-nombre="<?= h($nombre) ?>"
        <?php if (!$soloSeguridad): ?>
        data-original="<?= h($origUrl) ?>"
        <?php endif; ?>
      >
        <div class="d-flex align-items-center file-main-block">

          <input type="checkbox"
                 name="archivos[]"
                 value="<?= h($s3key) ?>"
                 data-nombre="<?= h($nombre) ?>"
                 class="mr-2 align-self-start">

          <img
            class="thumb-img js-thumb"
            src="img/loading.gif"
            data-thumb="<?= h($thumbUrl) ?>"
            width="32" height="32"
            loading="lazy"
            alt=""
          >

          <div>
            <div class="font-weight-bold">
              <?= h($nombre) ?>
              <span class="badge badge-light badge-ext text-uppercase"><?= h($ext) ?></span>
              <?php if ($seguro): ?>
                <span class="badge <?= $unlocked ? 'badge-info' : 'badge-warning' ?> ml-1 js-security-badge">
                  <?= $unlocked ? 'Desbloqueado' : 'Seguro' ?>
                </span>
              <?php else: ?>
                <span class="badge badge-secondary ml-1 js-security-badge">Normal</span>
              <?php endif; ?>
            </div>

            <small class="text-muted"
                   data-toggle="tooltip" data-placement="top"
                   title="<?= $metaTitle ?>">
              <?= h($rutaRow) ?>
              — <?= $fechaTxt ?>
              — <span class="text-mono"><?= h($keyEnc) ?></span>
             — <?= bytes_human($tamano) ?>
            </small>

<?php if (!$soloSeguridad): ?>
<?php if ($esAudio): ?>
  <div class="audio-player-pro mt-2">
    <div class="audio-controls audio-inline-row">
      <button type="button"
              class="btn btn-sm btn-primary btn-play"
              id="btn-<?= $rid ?>"
              onclick="wavePlayPause('<?= $rid ?>')">
        ▶
      </button>

      <span id="time-<?= $rid ?>" class="audio-time">0:00</span>
      <div id="wave-<?= $rid ?>" class="waveform waveform-inline"></div>
    </div>

    <audio id="audio-<?= $rid ?>"
           data-src="<?= h($origUrl) ?>"
           preload="none"
           style="display:none"></audio>
  </div>
<?php endif; ?>

<?php if ($esVideo): ?>
  <div class="d-flex align-items-center mt-1">
    <button type="button"
            class="btn btn-sm btn-outline-primary js-inline-video-open"
            data-key="<?= h($s3key) ?>"
            data-src="<?= h($origUrl) ?>"
            data-nombre="<?= h($nombre) ?>"
            data-video-index="<?= (int)$videoIndex ?>">
      <i class="fas fa-play-circle"></i> 
    </button>
  </div>
<?php endif; ?>
<?php endif; ?>

          </div>
        </div>

        <div class="d-flex align-items-center gap-2 file-actions-block">
          <?php if ($esImg && !$soloSeguridad): ?>
            <button type="button"
                    class="btn btn-sm btn-primary js-ver-imagen"
                    data-key="<?= h($s3key) ?>"
                    data-nombre="<?= h($nombre) ?>"
                    data-original="<?= h($origUrl) ?>" data-toggle="modal" data-target="#modalImagenUnica" title="VER IMAGEN">
              <i class="far fa-image"></i> 
            </button>
          <?php endif; ?>


<?php if (!$soloSeguridad): ?>

  <?php if ($ext === 'pdf'): ?>
    <button type="button"
            class="btn btn-sm btn-primary js-ver-pdf"
            data-key="<?= h($s3key) ?>" title="VER PDF">
      <i class="fas fa-file-pdf"></i>
    </button>
  <?php endif; ?>

  <?php if ($editTxt): ?>
    <a class="btn btn-sm btn-primary"
       href="editor.php?archivo=<?= $s3keyQ ?>"
       target="_blank" title="EDITAR">
      <i class="fas fa-edit"></i>
    </a>
  <?php endif; ?>

  <button type="button"
          class="btn btn-sm btn-primary js-rename-file"
          data-key="<?= h($s3key) ?>"
          data-nombre="<?= h($nombre) ?>" title="RENOMBRAR">
    <i class="fas fa-i-cursor"></i>
  </button>

  <a class="btn btn-sm btn-primary js-download-file"
     href="descargar_archivo.php?archivo=<?= $s3keyQ ?>&nombre=<?= urlencode($nombre) ?>"
     data-key="<?= h($s3key) ?>" title="DESCARGAR">
    <i class="fas fa-download"></i>
  </a>

  <button type="button"
          class="btn btn-sm btn-primary text-danger js-delete-one"
          data-archivo="<?= h($s3key) ?>" title="ELIMINAR">
    <i class="fas fa-trash-alt"></i>
  </button>

  <button type="button"
          class="btn btn-sm btn-primary js-move-one"
          data-key="<?= h($s3key) ?>" title="MOVER">
    <i class="fas fa-arrows-alt"></i>
  </button>

<?php endif; ?>

<?php if (!$soloSeguridad): ?>
  <button type="button"
          class="btn btn-sm btn-primary js-share"
          data-key="<?= h($s3key) ?>"
          data-ext="<?= h($ext) ?>" title="COMPARTIR">
    <i class="fas fa-share-alt"></i>
  </button>
<?php endif; ?>

<?php
/*
|--------------------------------------------------------------------------
| Estados del archivo
|--------------------------------------------------------------------------
| $yaEncript : el nombre físico en S3 ya fue transformado
| $seguro    : el archivo tiene seguridad (secure o unlocked)
| $unlocked  : el archivo está desbloqueado de forma persistente
|
| Reglas visuales:
| - ENCRIPTAR          -> solo si aún no está encriptado
| - PROTEGER           -> solo si no tiene seguridad
| - DESBLOQUEAR        -> si está en secure
| - BLOQUEAR           -> si está en unlocked
| - QUITAR PROTECCIÓN  -> solo si está en unlocked
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Botón dinámico de acceso
|--------------------------------------------------------------------------
| Este botón cambia automáticamente:
| - título
| - icono
| - color
| - estado data-unlocked
|--------------------------------------------------------------------------
*/
$unlockTitle = $unlocked ? 'BLOQUEAR' : 'DESBLOQUEAR';
$unlockIcon  = $unlocked ? 'fa-lock' : 'fa-unlock';
$unlockClass = $unlocked ? 'btn-success' : 'btn-warning';
?>

<?php if (!$yaEncript): ?>
  <!-- Encripta físicamente el archivo si aún no ha sido transformado -->
  <button type="button"
          class="btn btn-sm btn-primary btn-encriptar"
          data-key="<?= h($s3key) ?>"
          data-nombre="<?= h($nombre) ?>"
          title="ENCRIPTAR">
    <i class="fas fa-lock"></i>
  </button>
<?php endif; ?>

<?php if (!$seguro): ?>
  <!-- Activa protección persistente: AccessType = secure -->
  <button type="button"
          class="btn btn-sm btn-primary js-lock-file"
          data-key="<?= h($s3key) ?>"
          title="PROTEGER">
    <i class="fas fa-shield-alt"></i>
  </button>
<?php endif; ?>

<?php if ($seguro): ?>
  <!--
    Botón dinámico:
    - secure   -> DESBLOQUEAR / fa-unlock
    - unlocked -> BLOQUEAR    / fa-lock
  -->
  <button type="button"
          class="btn btn-sm <?= h($unlockClass) ?> js-unlock-file"
          data-key="<?= h($s3key) ?>"
          data-unlocked="<?= $unlocked ? '1' : '0' ?>"
          title="<?= h($unlockTitle) ?>">
    <i class="fas <?= h($unlockIcon) ?>"></i>
  </button>
<?php endif; ?>

<?php if ($seguro && $unlocked): ?>
  <!--
    Quita completamente la protección:
    - AccessType = normal
    - limpia PasswordHash
    - limpia SecureHint
  -->

  <button type="button"
          class="btn btn-sm btn-danger js-unsecure-one"
          data-key="<?= h($s3key) ?>"
          title="QUITAR PROTECCIÓN">
    <i class="fas fa-shield-virus"></i>
  </button>
<?php endif; ?>

<?php if (!$soloSeguridad): ?>
<?php if ($puedeTex): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia js-textract"
          data-key="<?= h($s3key) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="DOC2TXT"
          aria-label="DOC2TXT">
    <i class="fas fa-file-alt"></i>
    <span class="btn-texto-oculto">DOC2TXT</span>
  </button>
<?php endif; ?>

<?php if ($esAudio): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia js-transcribir"
          data-key="<?= h($s3key) ?>"
          data-nombre="<?= h($nombre) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="AUDIO2TXT"
          aria-label="AUDIO2TXT">
    <i class="fas fa-file-audio"></i>
    <span class="btn-texto-oculto">AUDIO2TXT</span>
  </button>
<?php endif; ?>
<?php if ($esVideo): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia js-transcribir"
          data-key="<?= h($s3key) ?>"
          data-nombre="<?= h($nombre) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="VIDEO2TXT"
          aria-label="VIDEO2TXT">
    <i class="fas fa-file-video"></i>
    <span class="btn-texto-oculto">VIDEO2TXT</span>
  </button>
<?php endif; ?>

<?php if ($editTxt): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia js-polly"
          data-key="<?= h($s3key) ?>"
          data-nombre="<?= h($nombre) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="TXT2AUDIO"
          aria-label="TXT2AUDIO">
    <i class="fas fa-headphones"></i>
    <span class="btn-texto-oculto">TXT2AUDIO</span>
  </button>
<?php endif; ?>

<?php if ($puedeTrd): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia js-traducir"
          data-key="<?= h($s3key) ?>"
          data-nombre="<?= h($nombre) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="DOC2TRADUCIR"
          aria-label="DOC2TRADUCIR">
    <i class="fas fa-language"></i>
    <span class="btn-texto-oculto">DOC2TRADUCIR</span>
  </button>
<?php endif; ?>

<?php if ($puedeAna): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia js-rekognition"
          data-key="<?= h($s3key) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="IMG2ANALISIS"
          aria-label="IMG2ANALISIS">
    <i class="fas fa-tags"></i>
    <span class="btn-texto-oculto">IMG2ANALISIS</span>
  </button>
<?php endif; ?>

<?php endif; ?>

   

              

        </div>
      </li>
    <?php endforeach; ?>
    <?php endif; ?>
  </ul>

  <?php
  $paginas = max(1, (int)ceil($total / $limite));
  if ($paginas > 1):
  ?>
    <nav class="mt-3" aria-label="Paginación">
      <ul class="pagination pagination-sm">
        <?php for ($p=1; $p <= $paginas; $p++): ?>
          <li class="page-item <?= $p === $pagina ? 'active' : '' ?>">
            <a class="page-link" href="#" data-pagina="<?= $p ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
  <?php endif; ?>
  

</div><!-- /archivosWrap -->
<script>
/* =========================================================
   BLOQUE ARCHIVOS - UI
   ========================================================= */

/* -------------------------
   1) Tooltips
------------------------- */
function initTooltipsBloqueArchivos() {
  try {
    if (window.jQuery && typeof jQuery.fn.tooltip === 'function') {
      jQuery('[data-toggle="tooltip"]').tooltip({ html:false, container:'body' });
    }
  } catch (e) {}
}

/* -------------------------
   2) Contexto global
------------------------- */
function initContextoBloqueArchivos() {
  window.rutaActual = <?= json_encode($rutaActual) ?>;
  window.imagenesGaleria = <?= json_encode($imagenesPagina, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
}

/* -------------------------
   3) Obtener límite actual
   - lo intenta leer desde URL
   - si no existe, desde inputs/select
------------------------- */
function getLimiteActualPaginacion() {
  const desdeSelect = document.querySelector('#formLimite select[name="limite"]');
  if (desdeSelect && desdeSelect.value && String(desdeSelect.value).trim() !== '') {
    return String(desdeSelect.value);
  }

  const desdeContexto = document.getElementById('archivosContexto')?.dataset?.limite;
  if (desdeContexto && String(desdeContexto).trim() !== '') {
    return String(desdeContexto);
  }

  const desdeUrl = new URLSearchParams(window.location.search).get('limite');
  if (desdeUrl && String(desdeUrl).trim() !== '') {
    return String(desdeUrl);
  }

  const candidatos = [
    document.querySelector('select[name="limite"]'),
    document.getElementById('limite'),
    document.querySelector('#formFiltros [name="limite"]')
  ];

  for (const el of candidatos) {
    if (el && el.value && String(el.value).trim() !== '') {
      return String(el.value);
    }
  }

  return null;
}

/* -------------------------
   4) Paginación AJAX
------------------------- */
function manejarClickPaginacionBloqueArchivos(e) {
  const a = e.target.closest('.pagination .page-link');
  if (!a) return;

  const p = parseInt(a.getAttribute('data-pagina') || '0', 10);
  if (!p) return;

  e.preventDefault();
  e.__archivosPaginationHandled = true;

  const params = new URLSearchParams(window.location.search);
  params.set('pagina', String(p));

  const filtrosActivos = (typeof window.obtenerFiltros === 'function')
    ? window.obtenerFiltros()
    : {
        buscar: document.querySelector('#formFiltros [name="buscar"]')?.value ?? '',
        tipo: document.querySelector('#formFiltros [name="tipo"]')?.value ?? '',
        fecha_inicio: document.querySelector('#formFiltros [name="fecha_inicio"]')?.value ?? '',
        fecha_fin: document.querySelector('#formFiltros [name="fecha_fin"]')?.value ?? ''
      };

  ['buscar', 'tipo', 'fecha_inicio', 'fecha_fin'].forEach((k) => {
    const v = filtrosActivos?.[k];
    if (v && String(v).trim() !== '') params.set(k, String(v));
    else params.delete(k);
  });

  const rutaActual = document.getElementById('archivosContexto')?.dataset?.rutaActual;
  if (rutaActual && String(rutaActual).trim() !== '') {
    params.set('ruta', String(rutaActual));
  }

  const limiteActual = getLimiteActualPaginacion();
  if (limiteActual) {
    params.set('limite', limiteActual);
  }

  if (window.actualizarBloqueArchivos) {
    window.actualizarBloqueArchivos(params);
  } else {
    const url = new URL(location.href);
    url.search = params.toString();
    location.href = url.toString();
  }
}

function initPaginacionBloqueArchivos() {
  if (window.__archivosPaginacionHandler) {
    document.removeEventListener('click', window.__archivosPaginacionHandler);
  }

  window.__archivosPaginacionHandler = manejarClickPaginacionBloqueArchivos;
  document.addEventListener('click', window.__archivosPaginacionHandler);
}

/* -------------------------
   5) Loader del bloque
------------------------- */
function initLoaderBloqueArchivos() {
  const wrap     = document.getElementById('archivosWrap');
  const overlay  = document.getElementById('archivosLoaderOverlay');
  const backdrop = document.getElementById('archivosLoaderBackdrop');
  const text     = document.getElementById('archivosLoaderText');

  if (!wrap || !overlay || !text) return;

  const showLoader = (msg) => {
    text.textContent = msg || 'Cargando…';
    overlay.style.display = 'block';
    if (backdrop) backdrop.style.display = 'block';
    wrap.classList.add('is-loading');
  };

  const hideLoader = () => {
    overlay.style.display = 'none';
    if (backdrop) backdrop.style.display = 'none';
    wrap.classList.remove('is-loading');
  };

  showLoader('Cargando datos…');

  const rows   = Array.from(wrap.querySelectorAll('li.file-row'));
  const thumbs = Array.from(wrap.querySelectorAll('img.js-thumb'));

  if (!rows.length) {
    hideLoader();
    return;
  }

  rows.forEach(r => {
    if (!r.querySelector('img.js-thumb')) {
      r.classList.remove('is-pending');
      r.classList.add('is-ready');
    }
  });

  let total = thumbs.length;
  let done  = 0;

  const showProgress = () => {
    const pct = total ? Math.round((done / total) * 100) : 100;
    text.textContent = `Cargando miniaturas… ${done}/${total} (${pct}%)`;
  };

  const revealRow = (img) => {
    const row = img.closest('li.file-row');
    if (!row) return;
    if (row.classList.contains('is-ready')) return;
    row.classList.remove('is-pending');
    row.classList.add('is-ready');
  };

  const finishIfReady = () => {
    if (done >= total) hideLoader();
  };

  showProgress();

  const hardTimeoutMs = 4500;
  const timer = setTimeout(() => {
    rows.forEach(r => {
      r.classList.remove('is-pending');
      r.classList.add('is-ready');
    });
    hideLoader();
  }, hardTimeoutMs);

  const markDone = (img) => {
    done++;
    revealRow(img);
    showProgress();
    finishIfReady();
    if (done >= total) clearTimeout(timer);
  };

  thumbs.forEach(img => {
    const onLoad = () => { cleanup(); markDone(img); };
    const onErr  = () => { cleanup(); markDone(img); };

    const cleanup = () => {
      img.removeEventListener('load', onLoad);
      img.removeEventListener('error', onErr);
    };

    img.addEventListener('load', onLoad, { once:true });
    img.addEventListener('error', onErr, { once:true });

    if (img.complete) {
      setTimeout(() => {
        cleanup();
        markDone(img);
      }, 0);
    }
  });
}

/* -------------------------
   6) Inicialización general
------------------------- */
(function initBloqueArchivos() {
  initTooltipsBloqueArchivos();
  initContextoBloqueArchivos();
  initPaginacionBloqueArchivos();
  initLoaderBloqueArchivos();
})();



window.deleteOne = window.deleteOne || async function deleteOne(archivo) {
  const key = String(archivo || '').trim();
  if (!key) return;

  if (!confirm('¿Eliminar este archivo?')) return;

  try {
    const body = new URLSearchParams({ archivo: key });
    const res = await fetch('eliminar_archivo.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body
    });

    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.estado !== 'ok') {
      throw new Error(json.mensaje || json.error || ('HTTP ' + res.status));
    }

    if (typeof window.actualizarBloqueArchivos === 'function') {
      await window.actualizarBloqueArchivos({ pagina: 1 });
    } else {
      location.reload();
    }
  } catch (err) {
    console.error(err);
    alert('❌ ' + (err.message || err));
  }
};

document.addEventListener('click', function (e) {
  const btn = e.target.closest('.js-delete-one');
  if (!btn || !btn.dataset) return;
  e.preventDefault();
  window.deleteOne(btn.dataset.archivo || '');
});

function getSeleccionadosBloqueArchivos() {
  const wrap = document.getElementById('archivosWrap') || document;
  return Array.from(wrap.querySelectorAll('input[name="archivos[]"]:checked')).map(cb => cb.value).filter(Boolean);
}

window.downloadSelected = window.downloadSelected || function downloadSelected() {
  const seleccionados = getSeleccionadosBloqueArchivos();
  if (!seleccionados.length) {
    alert('Selecciona al menos un archivo.');
    return;
  }

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'descargar_zip.php';
  form.style.display = 'none';

  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = 'archivos_json';
  input.value = JSON.stringify(seleccionados);

  form.appendChild(input);
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
};

window.moveSelected = function moveSelected() {
  const seleccionados = getSeleccionadosBloqueArchivos();
  if (!seleccionados.length) {
    alert('Selecciona al menos un archivo para mover.');
    return;
  }

  const jsonInput = document.getElementById('archivosJson');
  if (jsonInput) jsonInput.value = JSON.stringify(seleccionados);

  const modal = document.getElementById('modalMover');
  if (!modal) return;

  try {
    if (window.jQuery && window.$ && typeof window.$(modal).modal === 'function') {
      window.$(modal).modal('show');
      return;
    }
  } catch (e) {}

  try {
    if (window.bootstrap && window.bootstrap.Modal) {
      if (typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
      } else {
        (new window.bootstrap.Modal(modal)).show();
      }
      return;
    }
  } catch (e) {}

  const opener = document.querySelector('[data-toggle="modal"][data-target="#modalMover"], [data-bs-toggle="modal"][data-bs-target="#modalMover"]');
  if (opener) {
    opener.click();
    return;
  }

  modal.classList.add('show');
  modal.style.display = 'block';
};

(function initSeleccionBloqueArchivos(){
  const checkAll = document.getElementById('checkAllFiles');
  const updateCount = () => {
    const n = getSeleccionadosBloqueArchivos().length;
    const el = document.getElementById('filesSelectedCount');
    if (el) el.textContent = n ? `${n} seleccionado(s)` : '';
  };

  if (checkAll) {
    checkAll.addEventListener('change', function(){
      const checked = !!checkAll.checked;
      const wrap = document.getElementById('archivosWrap') || document;
      wrap.querySelectorAll('input[name="archivos[]"]').forEach(cb => { cb.checked = checked; });
      updateCount();
    });
  }

  document.addEventListener('change', function(e){
    if (!e.target || !e.target.matches('input[name="archivos[]"]')) return;
    updateCount();
  });

  updateCount();
})();

window.deleteSelected = window.deleteSelected || async function deleteSelected() {
  const seleccionados = getSeleccionadosBloqueArchivos();

  if (!seleccionados.length) {
    alert('Selecciona al menos un archivo.');
    return;
  }

  if (!confirm('¿Eliminar los archivos seleccionados?')) return;

  try {
    const body = new URLSearchParams({ archivos_json: JSON.stringify(seleccionados) });
    const res = await fetch('delete_multiple.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body
    });

    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.estado !== 'ok') {
      throw new Error(json.mensaje || json.error || ('HTTP ' + res.status));
    }

    if (typeof window.actualizarBloqueArchivos === 'function') {
      await window.actualizarBloqueArchivos({ pagina: 1 });
    } else {
      location.reload();
    }
  } catch (err) {
    console.error(err);
    alert('❌ ' + (err.message || err));
  }
};

</script>



<script>
(async function () {
  const MAX_TRIES = 12;
  const BASE_DELAY = 400;

  function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

  async function loadThumb(img) {
    const url = img.dataset.thumb;
    for (let i = 1; i <= MAX_TRIES; i++) {
      try {
        await new Promise((res, rej) => {
          const t = new Image();
          t.onload = () => res();
          t.onerror = () => rej();
          t.src = url + (url.includes('?') ? '&' : '?') + 't=' + Date.now() + '_' + i;
        });

        img.src = url + (url.includes('?') ? '&' : '?') + 'ok=' + Date.now();
        return true;
      } catch (e) {
        await sleep(BASE_DELAY * i);
      }
    }

    img.src = 'img/file.png';
    return false;
  }

  const imgs = Array.from(document.querySelectorAll('img.thumb-img[data-thumb]'));
  await Promise.allSettled(imgs.map(loadThumb));

  const loadingList = document.getElementById('loadingList');
  const fileList = document.getElementById('fileList');

  if (loadingList) loadingList.style.display = 'none';
  if (fileList) fileList.style.display = 'block';
})();
</script>