<?php
declare(strict_types=1);

use ArcadeCloud\Drive\View\FileViewHelper;
use ArcadeCloud\Drive\View\FileIconResolver;

require_once __DIR__ . '/app_bootstrap.php';

$app = drive_app();
$sessionManager = $app->session();
$sessionManager->start();
$sessionManager->requireAuthenticated('index.php');
$userId = $sessionManager->userId();

$rutaGet = trim((string) ($_GET['ruta'] ?? ''));
if ($rutaGet !== '') {
    $_SESSION['ruta_actual'] = $app->userStoragePath()->normalizeForUser($rutaGet, $userId);
}

$rutaActual = $app->userStoragePath()->normalizeForUser(
    (string) ($_SESSION['ruta_actual'] ?? ''),
    $userId
);
$_SESSION['ruta_actual'] = $rutaActual;

$state = $app->fileListService()->load($userId, $rutaActual, $_GET);
$filas = $state['rows'];
$total = $state['total'];
$pagina = $state['page'];
$limite = $state['limit'];
$buscar = $state['search'];
$tipo = $state['type'];
$fechaInicio = $state['date_from'];
$fechaFin = $state['date_to'];
$carpetaTotal = $state['folder_total'];
$carpetaBytes = (int) $state['folder_bytes'];

$imagenesExt = ['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff'];
$audioExt = ['mp3','wav','ogg','opus','m4a','aac'];
$videoExt = ['mp4','webm','mov','avi','mkv'];
$txtEditExt = ['txt','srt','vtt','md','html','css','js','php','py','json','csv','sql','jas'];
$textractExt = ['jpg','jpeg','png','tif','tiff','pdf'];
$traducirExt = ['txt','pdf','jpg','jpeg','png','tif','tiff'];
$analizarExt = ['jpg','jpeg','png'];
$transcribeExt = ['amr','flac','m4a','mp3','mp4','ogg','webm','wav'];
$comprehendExt = ['txt','jas','md','markdown','csv','json','html','htm','xml','sql','log','srt','vtt','php','phtml','js','mjs','css','py','ini','cfg','conf','yaml','yml'];

$imagenesPagina = [];
$visibles = 0;
$noVisibles = 0;
$segurosAbiertos = 0;

foreach ($filas as $row) {
    $ext = FileViewHelper::extension((string) ($row['Nombre'] ?? ''));
    if (in_array($ext, $imagenesExt, true) && !FileViewHelper::isLocked($row)) {
        $imagenesPagina[] = [
            'key' => FileViewHelper::buildS3Key(
                (string) ($row['Ruta'] ?? ''),
                (string) ($row['Encriptado'] ?? '')
            ),
            'nombre' => (string) ($row['Nombre'] ?? ''),
        ];
    }

    if (FileViewHelper::isLocked($row)) {
        $noVisibles++;
    } else {
        $visibles++;
        if (FileViewHelper::hasSecurity($row)) {
            $segurosAbiertos++;
        }
    }
}
?>

<div class="archivos-wrap is-loading" id="archivosWrap">

  <!-- Contexto persistente para JS -->
  <div id="archivosContexto"
       data-ruta-actual="<?= FileViewHelper::escape($rutaActual) ?>"
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
  Peso: <strong><?= FileViewHelper::formatBytes($carpetaBytes) ?></strong> |
  Ruta: <code><?= FileViewHelper::escape($rutaActual) ?></code> |
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

      $s3key  = FileViewHelper::buildS3Key((string)$rutaRow, (string)$keyEnc);
      $s3keyQ = rawurlencode($s3key);

      $origUrl  = "ver_archivo.php?archivo={$s3keyQ}";

      $tamano    = (int)($row['Tamano'] ?? 0);
      $fechaTxt  = date('Y-m-d H:i', strtotime($row['Fecha'] ?? 'now'));
      $ext       = FileViewHelper::extension($nombre);
      $metaTitle = FileViewHelper::metadataTooltip($row['Metadatos'] ?? null);
      $metaData = FileViewHelper::metadataArray($row['Metadatos'] ?? null);
      $metaJson = json_encode($metaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $iconInfo = FileIconResolver::resolve($ext);
      $thumbUrl = 'thumb.php?key=' . rawurlencode($s3key) . '&w=96&h=96&fit=cover';

      $esImg   = in_array($ext, $imagenesExt, true);
      $esAudio = in_array($ext, $audioExt, true);
      $esVideo = in_array($ext, $videoExt, true);
      $editTxt = in_array($ext, $txtEditExt, true);
      $puedeTex= in_array($ext, $textractExt, true);
      $puedeTrd= in_array($ext, $traducirExt, true);
      $puedeAna= in_array($ext, $analizarExt, true);
      $puedeTranscribir = in_array($ext, $transcribeExt, true);
      $puedeComprehend = in_array($ext, $comprehendExt, true);

      $yaEncript = FileViewHelper::isEncrypted($row);
        $bloqueado = FileViewHelper::isLocked($row);
        $seguro    = FileViewHelper::hasSecurity($row);
        $unlocked  = (($row['AccessType'] ?? 'normal') === 'unlocked');
        $soloSeguridad = $bloqueado;

      $rid = substr(md5($s3key),0,10);
      if ($esVideo) $videoIndex++;

      $clsEnc = $yaEncript ? 'enc-yes' : 'enc-no';
      $clsSec = $seguro    ? 'sec-yes' : 'sec-no';
    ?>
     <li
  class="list-group-item d-flex justify-content-between align-items-center file-row <?= $esAudio ? 'file-row-audio' : '' ?> <?= $clsEnc ?> <?= $clsSec ?> is-ready"
        data-secure="<?= $seguro ? '1' : '0' ?>"
        data-unlocked="<?= $unlocked ? '1' : '0' ?>"
        data-secure-hint="<?= FileViewHelper::escape((string)($row['SecureHint'] ?? '')) ?>"
        data-tipo="archivo"
        data-ext="<?= FileViewHelper::escape($ext) ?>"
        data-key="<?= FileViewHelper::escape($s3key) ?>"
        data-nombre="<?= FileViewHelper::escape($nombre) ?>"
        <?php if (!$soloSeguridad): ?>
        data-original="<?= FileViewHelper::escape($origUrl) ?>"
        <?php endif; ?>
      >
        <div class="d-flex align-items-center file-main-block">

          <input type="checkbox"
                 name="archivos[]"
                 value="<?= FileViewHelper::escape($s3key) ?>"
                 data-nombre="<?= FileViewHelper::escape($nombre) ?>"
                 class="mr-2 align-self-start">

          <?php if ($esImg && !$soloSeguridad): ?>
            <button type="button"
                    class="file-preview-button js-ver-imagen"
                    data-key="<?= FileViewHelper::escape($s3key) ?>"
                    data-nombre="<?= FileViewHelper::escape($nombre) ?>"
                    data-original="<?= FileViewHelper::escape($origUrl) ?>"
                    title="Ver imagen completa">
              <img
                class="thumb-img file-thumb-image"
                src="<?= FileViewHelper::escape($thumbUrl) ?>"
                width="56" height="56"
                loading="lazy"
                decoding="async"
                alt="Miniatura de <?= FileViewHelper::escape($nombre) ?>"
              >
            </button>
          <?php else: ?>
            <?php
              $visualIcon = $soloSeguridad
                  ? ['icon' => 'fa-lock', 'category' => 'locked', 'label' => 'Archivo protegido']
                  : $iconInfo;
            ?>
            <span class="file-type-icon file-type-<?= FileViewHelper::escape($visualIcon['category']) ?>"
                  title="<?= FileViewHelper::escape($visualIcon['label']) ?>"
                  aria-label="<?= FileViewHelper::escape($visualIcon['label']) ?>">
              <i class="fas <?= FileViewHelper::escape($visualIcon['icon']) ?>" aria-hidden="true"></i>
            </span>
          <?php endif; ?>

          <div>
            <div class="font-weight-bold">
              <?= FileViewHelper::escape($nombre) ?>
              <span class="badge badge-light badge-ext text-uppercase"><?= FileViewHelper::escape($ext) ?></span>
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
              <?= FileViewHelper::escape($rutaRow) ?>
              — <?= $fechaTxt ?>
              — <span class="text-mono"><?= FileViewHelper::escape($keyEnc) ?></span>
             — <?= FileViewHelper::formatBytes($tamano) ?>
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
           data-src="<?= FileViewHelper::escape($origUrl) ?>"
           preload="none"
           style="display:none"></audio>
  </div>
<?php endif; ?>

<?php if ($esVideo): ?>
  <div class="d-flex align-items-center mt-1">
    <button type="button"
            class="btn btn-sm btn-outline-primary js-inline-video-open"
            data-key="<?= FileViewHelper::escape($s3key) ?>"
            data-src="<?= FileViewHelper::escape($origUrl) ?>"
            data-nombre="<?= FileViewHelper::escape($nombre) ?>"
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
                    data-key="<?= FileViewHelper::escape($s3key) ?>"
                    data-nombre="<?= FileViewHelper::escape($nombre) ?>"
                    data-original="<?= FileViewHelper::escape($origUrl) ?>" data-toggle="modal" data-target="#modalImagenUnica" title="VER IMAGEN">
              <i class="far fa-image"></i> 
            </button>
          <?php endif; ?>


<?php if (!$soloSeguridad): ?>

  <?php if ($ext === 'pdf'): ?>
    <button type="button"
            class="btn btn-sm btn-primary js-ver-pdf"
            data-key="<?= FileViewHelper::escape($s3key) ?>" title="VER PDF">
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
          class="btn btn-sm btn-primary js-file-metadata"
          data-nombre="<?= FileViewHelper::escape($nombre) ?>"
          data-meta="<?= FileViewHelper::escape($metaJson ?: '{}') ?>"
          title="METADATOS">
    <i class="fas fa-info-circle"></i>
  </button>

  <button type="button"
          class="btn btn-sm btn-primary js-rename-file"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-nombre="<?= FileViewHelper::escape($nombre) ?>" title="RENOMBRAR">
    <i class="fas fa-i-cursor"></i>
  </button>

  <a class="btn btn-sm btn-primary js-download-file"
     href="descargar_archivo.php?archivo=<?= $s3keyQ ?>&nombre=<?= urlencode($nombre) ?>"
     data-key="<?= FileViewHelper::escape($s3key) ?>" title="DESCARGAR">
    <i class="fas fa-download"></i>
  </a>

  <button type="button"
          class="btn btn-sm btn-primary text-danger js-delete-one"
          data-archivo="<?= FileViewHelper::escape($s3key) ?>" title="ELIMINAR">
    <i class="fas fa-trash-alt"></i>
  </button>

  <button type="button"
          class="btn btn-sm btn-primary js-move-one"
          data-key="<?= FileViewHelper::escape($s3key) ?>" title="MOVER">
    <i class="fas fa-arrows-alt"></i>
  </button>

<?php endif; ?>

<?php if (!$soloSeguridad): ?>
  <button type="button"
          class="btn btn-sm btn-primary js-share"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-ext="<?= FileViewHelper::escape($ext) ?>" title="COMPARTIR">
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
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-nombre="<?= FileViewHelper::escape($nombre) ?>"
          title="ENCRIPTAR">
    <i class="fas fa-lock"></i>
  </button>
<?php endif; ?>

<?php if (!$seguro): ?>
  <!-- Activa protección persistente: AccessType = secure -->
  <button type="button"
          class="btn btn-sm btn-primary js-lock-file"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
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
          class="btn btn-sm <?= FileViewHelper::escape($unlockClass) ?> js-unlock-file"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-unlocked="<?= $unlocked ? '1' : '0' ?>"
          title="<?= FileViewHelper::escape($unlockTitle) ?>">
    <i class="fas <?= FileViewHelper::escape($unlockIcon) ?>"></i>
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
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          title="QUITAR PROTECCIÓN">
    <i class="fas fa-shield-virus"></i>
  </button>
<?php endif; ?>

<?php if (!$soloSeguridad): ?>
<?php if ($puedeTex): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-textract"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="Amazon Textract · Extraer texto"
          aria-label="Amazon Textract · Extraer texto">
    <i class="fas fa-file-alt"></i>
    <span class="aws-action-label">Extraer texto</span>
  </button>
<?php endif; ?>

<?php if ($puedeTranscribir && !$esVideo): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-transcribir"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-nombre="<?= FileViewHelper::escape($nombre) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="Amazon Transcribe · Audio a texto"
          aria-label="Amazon Transcribe · Audio a texto">
    <i class="fas fa-file-audio"></i>
    <span class="aws-action-label">Transcribir</span>
  </button>
<?php endif; ?>
<?php if ($puedeTranscribir && $esVideo): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-transcribir"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-nombre="<?= FileViewHelper::escape($nombre) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="Amazon Transcribe · Video a texto"
          aria-label="Amazon Transcribe · Video a texto">
    <i class="fas fa-file-video"></i>
    <span class="aws-action-label">Transcribir</span>
  </button>
<?php endif; ?>

<?php if ($editTxt): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-polly"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-nombre="<?= FileViewHelper::escape($nombre) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="Amazon Polly · Texto a audio"
          aria-label="Amazon Polly · Texto a audio">
    <i class="fas fa-headphones"></i>
    <span class="aws-action-label">Crear audio</span>
  </button>
<?php endif; ?>

<?php if ($puedeTrd): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-traducir"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-nombre="<?= FileViewHelper::escape($nombre) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="Amazon Translate · Traducir"
          aria-label="Amazon Translate · Traducir">
    <i class="fas fa-language"></i>
    <span class="aws-action-label">Traducir</span>
  </button>
<?php endif; ?>

<?php if ($puedeAna): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-rekognition"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="Amazon Rekognition · Analizar imagen"
          aria-label="Amazon Rekognition · Analizar imagen">
    <i class="fas fa-tags"></i>
    <span class="aws-action-label">Analizar imagen</span>
  </button>
<?php endif; ?>

<?php if ($puedeComprehend): ?>
  <button type="button"
          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-comprehend"
          data-key="<?= FileViewHelper::escape($s3key) ?>"
          data-nombre="<?= FileViewHelper::escape($nombre) ?>"
          data-toggle="tooltip"
          data-placement="top"
          title="Amazon Comprehend · Analizar texto"
          aria-label="Amazon Comprehend · Analizar texto">
    <i class="fas fa-brain"></i>
    <span class="aws-action-label">Analizar texto</span>
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
  const wrap = document.getElementById('archivosWrap');
  const overlay = document.getElementById('archivosLoaderOverlay');
  const backdrop = document.getElementById('archivosLoaderBackdrop');
  if (wrap) {
    wrap.querySelectorAll('li.file-row').forEach(row => {
      row.classList.remove('is-pending');
      row.classList.add('is-ready');
    });
    wrap.classList.remove('is-loading');
  }
  if (overlay) overlay.style.display = 'none';
  if (backdrop) backdrop.style.display = 'none';
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

  if (window.__bloqueArchivosSelectionHandler) {
    document.removeEventListener('change', window.__bloqueArchivosSelectionHandler);
  }
  window.__bloqueArchivosSelectionHandler = function(e){
    if (!e.target || !e.target.matches('input[name="archivos[]"]')) return;
    updateCount();
  };
  document.addEventListener('change', window.__bloqueArchivosSelectionHandler);

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

    try { document.dispatchEvent(new Event('drive:storage-changed')); } catch (_) {}
  } catch (err) {
    console.error(err);
    alert('❌ ' + (err.message || err));
  }
};

</script>



