<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app_bootstrap.php';

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');
$userId = $session->userId();

// Provisionamiento multiusuario idempotente:
// user 1 => Data/, user 2 => Data2/, user N => DataN/.
$userRoot = $app->userStorageProvisioner()->ensureRoot($userId);
$_SESSION['ruta_actual'] = $app->userStoragePath()->normalizeForUser(
    (string) ($_SESSION['ruta_actual'] ?? $userRoot),
    $userId
);

$pageService = $app->drivePageService();
$vm = $pageService->build($_SESSION, $_GET, $userId);

$basePrefix = $vm->basePrefix;

/*
 * ============================================================
 * DESTINOS PARA MOVER ARCHIVOS
 * ============================================================
 * DB-FIRST:
 * No consultamos S3 para construir este listado.
 * Se muestran únicamente las carpetas del usuario autenticado.
 * ============================================================
 */
$todasLasCarpetas = $app->s3Manager()->listarCarpetasDesdeDb(
    $userId,
    $userRoot,
    true
);

$tipo = $vm->tipo;
$buscar = $vm->buscar;
$fechaInicio = $vm->fechaInicio;
$fechaFin = $vm->fechaFin;
$limite = $vm->limite;
$pagina = $vm->pagina;
$error = $vm->error;
$extensiones_unicas = $vm->extensionesUnicas;

$storageUsage = $app->storageUsageService()->getUsage($userId);
$footerRutaActual = $basePrefix;
$footerEspacioUsado = $storageUsage['formatted'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Cloud Drive</title>

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="icon" href="ellogo.png" type="image/x-icon">

  <link rel="stylesheet" href="css/styles.css">

  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js"></script>
  <script src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js"></script>
</head>

<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on">

<nav class="navbar navbar-expand-lg navbar-dark px-3">
  <a class="navbar-brand" href="s3.php">
    <!-- <img src="../assets/img/icono.png" width="30" height="30" class="d-inline-block align-top" alt="Logo"> Cloud Drive -->
    <img src="ellogo.png" width="30" height="30" class="rounded-circle mr-2" width="30" height="30" alt="Logo"> Cloud Drive
  </a>

    <div class="form-inline my-2 my-lg-0 ml-auto">
        <button id="btnRecargar" class="btn btn-primary ml-2" onclick="recargarPagina()" title="Recargar página">
        <i class="fas fa-sync-alt"></i>
      </button>
      <button class="btn btn-outline-light ml-2" data-toggle="modal" data-target="#modalBusquedaGlobal" title="Buscar en todas las carpetas">
        <i class="fas fa-search-plus"></i>
      </button>
    </div>


    <ul class="navbar-nav ml-3">
       <li class="nav-item dropdown ml-2">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="temaMenu" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="fas fa-palette mr-1"></i> Diseño
          </a>
        
          <div class="dropdown-menu dropdown-menu-right" aria-labelledby="temaMenu" style="min-width:280px;">
            
            <h6 class="dropdown-header">Color neón</h6>
            <button class="dropdown-item js-set-theme" data-theme="theme-neon-green">Verde neón</button>
            <button class="dropdown-item js-set-theme" data-theme="theme-neon-blue">Azul neón</button>
            <button class="dropdown-item js-set-theme" data-theme="theme-neon-red">Rojo neón</button>
            <button class="dropdown-item js-set-theme" data-theme="theme-neon-yellow">Amarillo neón</button>
        
            <div class="dropdown-divider"></div>
        
            <h6 class="dropdown-header">Modo</h6>
            <button class="dropdown-item js-set-mode" data-mode="theme-dark">Oscuro</button>
            <button class="dropdown-item js-set-mode" data-mode="theme-light">Claro</button>
        
            <div class="dropdown-divider"></div>
        
            <h6 class="dropdown-header">Visión</h6>
            <button class="dropdown-item js-set-vision" data-vision="vision-normal">Normal</button>
            <button class="dropdown-item js-set-vision" data-vision="vision-myopia">Miopía</button>
            <button class="dropdown-item js-set-vision" data-vision="vision-protanopia">Protanopia</button>
            <button class="dropdown-item js-set-vision" data-vision="vision-deuteranopia">Deuteranopia</button>
            <button class="dropdown-item js-set-vision" data-vision="vision-tritanopia">Tritanopia</button>
        
            <div class="dropdown-divider"></div>
        
            <button class="dropdown-item" id="btnToggleAscii">
              <i class="fas fa-terminal mr-1"></i> Alternar ASCII
            </button>
        
          </div>
        </li>    
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle d-flex align-items-center text-white" href="#" id="usuarioMenu" role="button" data-toggle="dropdown">
          <img src="logo1.png" alt="Perfil" class="rounded-circle mr-2" width="30" height="30">
          <?= htmlspecialchars($_SESSION['usuario']) ?>
        </a>

        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="usuarioMenu">
          <button class="dropdown-item" data-toggle="modal" data-target="#modalEnlacesUtiles">
            <i class="fas fa-link"></i> Enlaces
          </button>
         
          <button class="dropdown-item" data-toggle="modal" data-target="#modalCostosAws">
              <i class="fas fa-chart-line"></i> Costos AWS
          </button>
           <button id="btnSyncS3" class="dropdown-item">
              <i class="fas fa-rotate"></i> Sincronizar S3
          </button>
          <div class="dropdown-divider"></div>
           <a class="dropdown-item text-danger" href="logout.php">
            <i class="fas fa-sign-out-alt"></i> Cerrar sesión
          </a>
        </div>
      </li>
    </ul>

</nav>


<div class="container-fluid">

  <div class="row">
    <!-- Panel lateral -->
    <!--<div class="col-md-3 p-3 bg-white border-right">-->
        <div class="col-md-3 p-3 sidebar">
        
       <?php include 'bloque_carpetas.php'; ?>


    <div id="mensajeOperacion" class="alert alert-success d-none" role="alert"></div>


<!-- Reproductor de video acoplado a la izquierda -->
<div id="videoDock" style="display:none">
  <video id="videoDockPlayer" controls preload="none" class="w-100">
    <source id="videoDockSource" src="">
  </video>

  <div class="mt-2 text-center">
    <div id="videoDockName" class="small text-truncate mx-auto" style="max-width:95%;">—</div>

    <div class="btn-toolbar justify-content-center mt-2" role="toolbar" aria-label="Video controls">
      <div class="btn-group btn-group-sm" role="group">
        <button id="btnVideoPrev" class="btn btn-outline-secondary" type="button" title="Anterior">
          <i class="fas fa-step-backward"></i>
        </button>
        <button id="btnVideoPlayPause" class="btn btn-outline-primary" type="button" title="Reproducir / Pausar">
          <i class="fas fa-play"></i> / <i class="fas fa-pause"></i>
        </button>
        <button id="btnVideoNext" class="btn btn-outline-secondary" type="button" title="Siguiente">
          <i class="fas fa-step-forward"></i>
        </button>
      </div>
    </div>
  </div>
</div>


    </div>
    
    <!-- Panel principal -->
    <div class="col-md-9 p-4">
 <!--
<h4 id="tituloRutaActual">
  <i class="fas fa-folder-open"></i> Archivos en <strong id="textoRuta"><?= htmlspecialchars($_SESSION['ruta_actual'] ?? Config::RUTA_RAIZ) ?></strong>
</h4>
-->


<!-- 1. Tus pestañas -->

<ul class="nav nav-tabs" id="mainTabs" role="tablist">
<li class="nav-item">
    <a class="nav-link active" id="tab-archivos" data-toggle="tab"
       href="#pane-archivos" role="tab" aria-controls="pane-archivos"
       aria-selected="true">
      Archivos
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="tab-Subir" data-toggle="tab"
       href="#pane-Subir" role="tab" aria-controls="pane-Subir"
       aria-selected="false">
      Subir Archivos
    </a>
  </li>
</ul>

<div class="tab-content" id="mainTabsContent">

<!-- PESTAÑA ARCHIVOS -->
<div class="tab-pane fade show active" id="pane-archivos"
       role="tabpanel" aria-labelledby="tab-archivos">
    <!-- Aquí va todo tu contenido de Archivos -->
      <div class="container-fluid">
      <div class="row">
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

     <div class="d-flex align-items-center justify-content-between mb-2" style="gap: 10px;">
      <h6 class="mb-0">Archivos</h6>
      <button class="btn btn-outline-secondary btn-sm" type="button"
              data-toggle="collapse" data-target="#panelFiltrosArchivos"
              data-bs-toggle="collapse" data-bs-target="#panelFiltrosArchivos"
              aria-expanded="false" aria-controls="panelFiltrosArchivos"
              aria-label="Mostrar u ocultar filtros"
              title="Mostrar/Ocultar filtros">
        <i class="fas fa-ellipsis-v"></i>
      </button>
    </div>

    <div id="panelFiltrosArchivos" class="collapse mb-3">
      <div class="d-flex flex-wrap align-items-end" style="gap: 10px;">
        <!-- Formulario de cantidad -->
        <form id="formLimite" class="form-inline" onsubmit="return false;">
          <input type="hidden" name="ruta" value="<?= htmlspecialchars($basePrefix) ?>">
          <input type="hidden" name="buscar" value="<?= htmlspecialchars($_GET['buscar'] ?? '') ?>">
          <input type="hidden" name="fecha_inicio" value="<?= htmlspecialchars($_GET['fecha_inicio'] ?? '') ?>">
          <input type="hidden" name="fecha_fin" value="<?= htmlspecialchars($_GET['fecha_fin'] ?? '') ?>">
          <input type="hidden" name="tipo" value="<?= htmlspecialchars($_GET['tipo'] ?? '') ?>">

          <label class="mr-2 mb-2">Mostrar:</label>
          <select name="limite" class="form-control mr-2 mb-2" style="max-width: 100px;">␊
            <?php foreach ([5, 10, 20, 50] as $op): ?>
              <option value="<?= $op ?>" <?= $limite === $op ? 'selected' : '' ?>><?= $op ?></option>␊
            <?php endforeach; ?>␊
          </select>
        </form>

        <!-- Formulario de filtros -->
          <form id="formFiltros" class="form-inline" onsubmit="return false;">
            <input type="hidden" name="ruta" value="<?= htmlspecialchars($basePrefix) ?>">
            <input type="hidden" name="limite" value="<?= (int)($_GET['limite'] ?? 5) ?>">

            <input type="text" name="buscar" class="form-control mr-2 mb-2" placeholder="Buscar..." value="<?= htmlspecialchars($_GET['buscar'] ?? '') ?>" style="max-width: 160px;">
            <input type="date" name="fecha_inicio" class="form-control mr-2 mb-2" value="<?= htmlspecialchars($_GET['fecha_inicio'] ?? '') ?>" style="max-width: 150px;">
            <input type="date" name="fecha_fin" class="form-control mr-2 mb-2" value="<?= htmlspecialchars($_GET['fecha_fin'] ?? '') ?>" style="max-width: 150px;">

            <select name="tipo" class="form-control mr-2 mb-2" style="max-width: 140px;">
              <option value="">Todos los tipos</option>
              <?php foreach ($extensiones_unicas as $ext): ?>
                <option value="<?= $ext ?>" <?= $ext === $tipo ? 'selected' : '' ?>>.<?= $ext ?></option>
              <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-primary mr-2 mb-2">Filtrar</button>
            <button type="button" id="btnQuitarFiltros" class="btn btn-outline-secondary mb-2" style="display: inline;">
              Quitar filtros
            </button>
          </form>
        
                    <!-- Opciones de reproducción -->
            <div id="ap-controls" class="d-flex align-items-center mb-2">
              <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="apModeRepeat">
                <label class="custom-control-label" for="apModeRepeat">Repetir pista</label>
                <small class="text-muted ml-2">Si no está activado, al terminar avanza a la siguiente.</small>
              </div>
              
            </div>
      </div>
    </div>

<form id="multiDeleteForm" action="delete_multiple.php" method="POST" class="w-100">
          <input type="hidden" name="ruta" value="<?= htmlspecialchars($basePrefix) ?>">
          <div id="bloque-archivos">
            <?php
              $_GET['ruta'] = $basePrefix;
              include 'bloque_archivos.php';
            ?>
          </div>
        </form>

<div id="syncStatus" class="mb-2 sync-green"></div>

  
      </div>
    </div>
  </div>

<!-- PESTAÑA Cargar -->
<div class="tab-pane fade" id="pane-Subir" role="tabpanel" aria-labelledby="tab-Subir">

  
<!-- Formulario de subida sin recarga -->
<div class="upload-form-container card p-3 shadow-sm">
  <h6><i class="fas fa-upload"></i> Subir archivo</h6>
  
  <div class="card p-3 my-2">
  <label class="form-label">Subir desde URL (Drive / directo)</label>
  <div class="input-group">
    <input id="urlRemota" type="url" class="form-control" placeholder="https://...">
    <button id="btnSubirUrl" class="btn btn-primary">
      <span id="spinnerUrl" class="spinner-border spinner-border-sm d-none"></span>
      <span id="btnTxtUrl">Subir</span>
    </button>
  </div>
  <div id="uploadUrlResult" class="small text-muted mt-2"></div>
</div>

  
  <div class="card mb-3">
  <div class="card-body">

    <div class="small mb-2">
      <strong>¿Deseas subir archivos?</strong><br>
      Puedes utilizar el siguiente formulario para subir archivos de <strong>gran tamaño (más de 1 GB)</strong>. 
      Primero selecciona el archivo y luego pulsa <em>Subir</em>. 
      Asegúrate de <strong>navegar por las carpetas a tu izquierda</strong> y posicionarte en aquella donde deseas que se guarde el archivo.
    </div>

    <form id="uploadForm">
      
      <div class="file-input-wrapper mb-2">
        <div class="file-input-button btn btn-primary btn-sm">
          <i class="fas fa-folder-open"></i> Seleccionar Archivo
        </div>
        <input type="file" id="archivo" required>
      </div>
      
      <div id="fileName" class="file-name text-muted small mb-2"></div>
      
      <div class="progress mb-2" id="progressBar" style="display:none;">
        <div class="progress-bar progress-bar-striped progress-bar-animated"
             role="progressbar" style="width: 0%"></div>
      </div>
      
      <button type="submit" class="btn btn-success btn-sm">
        <span class="spinner-border spinner-border-sm d-none" id="spinner" role="status"></span>
        <span id="buttonText">Subir Archivo</span>
      </button>

    </form>

  </div>
</div>
  <!-- Mensajes -->
  <div id="uploadResult" class="mt-2 text-muted small">
    Selecciona un archivo y haz clic en Subir
  </div>
  

        
</div>
        <form action="api/upload.php?mode=dropbox&action=init" class="dropzone mb-4" id="dropzonePublico">
          <div class="dz-message">Arrastra aquí o haz clic para subir</div>
        </form>

<!-- SUBIDA GRANDE (CHUNKED 15MB) - versión con menos botones -->
<div class="card p-3 shadow-sm mt-3" id="chunkedCard">
  <h6 class="mb-2"><i class="fas fa-layer-group"></i> Subida grande (por partes 15MB)</h6>

 <div class="card mb-2">
  <div class="card-body small py-2">
    Recomendado para archivos grandes (ej. &gt; 200MB / 1GB+). 
    Muestra progreso real, permite reintentos y reanudar.
  </div>
</div>

  <!-- Selector estilo igual al normal -->
  <div class="file-input-wrapper mb-2">
    <div class="file-input-button btn btn-primary btn-sm">
      <i class="fas fa-folder-open"></i> Seleccionar Archivo (Grande)
    </div>
    <input type="file" id="archivoGrande">
  </div>

  <div class="text-muted small mb-2" id="fileNameGrande"></div>

  <div class="progress mb-2" id="progressBarGrande" style="display:none;">
    <div class="progress-bar progress-bar-striped progress-bar-animated"
         id="progressBarGrandeInner" role="progressbar" style="width: 0%"></div>
  </div>

  <div class="d-flex align-items-center gap-2">
    <button type="button" class="btn btn-success btn-sm" id="btnSubirGrande">
      <span class="spinner-border spinner-border-sm d-none" id="spinnerGrande" role="status"></span>
      <span id="btnTxtGrande">Subir (15MB)</span>
    </button>

    <!-- Cancelar como link (menos “ruido visual”) -->
    <a href="#" class="small text-secondary d-none" id="btnCancelarGrande">Cancelar</a>
  </div>

  <div id="uploadResultGrande" class="mt-2 text-muted small">
    Selecciona un archivo y pulsa Subir (15MB)
  </div>
</div>

</div>

<div id="bloque-footer">
  <?php include 'bloque_footer.php'; ?>
</div>

</div>

 

</div>
     

     
</div>
  

<!-- Agrega esta parte al final del body para mostrar el modal -->

<!-- MODAL DE BÚSQUEDA GLOBAL ACTUALIZADO -->
<div class="modal fade" id="modalBusquedaGlobal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title"><i class="fas fa-search"></i> Buscar archivo en todas las carpetas</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <form id="formBusquedaGlobal">
          <div class="form-row">
            <div class="form-group col-md-8">
              <label for="terminoBusqueda">Buscar por nombre</label>
              <input type="text" class="form-control" id="terminoBusqueda" name="termino" placeholder="factura, fact*, *.pdf, *2026*" required>
              <small class="form-text text-muted">
                Texto normal busca en cualquier parte del nombre. <code>fact*</code> busca al inicio,
                <code>*.pdf</code> al final, <code>*2026*</code> en cualquier parte y <code>?</code> representa un carácter.
              </small>
            </div>
            <div class="form-group col-md-4 align-self-end">
              <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-search"></i> Buscar
              </button>
            </div>
          </div>
        </form>
        <hr>
        <div id="resultadosBusqueda"></div>
      </div>
    </div>
  </div>
</div>
<!-- MODAL: Ver imagen (1 por slide) -->
<div class="modal fade" id="modalImagenUnica" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content bg-dark text-white" style="background:#000!important;">
      <div class="modal-header border-0" style="background:#000;">
        <h5 class="modal-title"><i class="far fa-image"></i> Visor</h5>
        <button type="button" class="modal-x" data-dismiss="modal" aria-label="Cerrar">×</button>
      </div>
      <div class="modal-body p-0" style="background:#000;">
        <div id="gu-carousel-wrap"></div>
      </div>
    </div>
  </div>
</div>
<!-- MODAL: Galería grid -->
<div class="modal fade" id="modalGaleriaCompleta" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content bg-dark text-white" style="background:#000!important;">
      <div class="modal-header border-0" style="background:#000;">
        <h5 class="modal-title"><i class="fas fa-images"></i> Galería</h5>
        <button type="button" class="modal-x" data-dismiss="modal" aria-label="Cerrar">×</button>
      </div>
      <div class="modal-body" style="background:#000;">
        <div id="gg-carousel-wrap"></div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Crear Carpeta -->
<div class="modal fade" id="modalCrearCarpeta" tabindex="-1" role="dialog" aria-labelledby="modalCrearCarpetaLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <form id="formCrearCarpeta" method="POST">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modalCrearCarpetaLabel">Crear carpeta</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="ruta" id="crearCarpetaRuta" value="<?= htmlspecialchars($basePrefix) ?>">

          <div class="form-group">
            <label for="crearCarpetaNombre">Nombre de la carpeta</label>
            <input type="text"
                   class="form-control"
                   name="nueva"
                   id="crearCarpetaNombre"
                   placeholder="Nueva carpeta"
                   required>
          </div>

          <small class="text-muted d-block">
            Ruta actual:
            <span id="crearCarpetaRutaTexto"><?= htmlspecialchars($basePrefix) ?></span>
          </small>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="btnCrearCarpeta">Crear</button>
        </div>
      </div>
    </form>
  </div>
</div>
<!-- Modal ELIMINAR CARPETA -->
<div class="modal fade" id="modalEliminarCarpeta" tabindex="-1" role="dialog" aria-labelledby="modalEliminarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <form id="formEliminarCarpeta" class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="modalEliminarLabel">Eliminar carpeta</h5>
        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="ruta" id="eliminarRuta">
        <p class="mb-2">Vas a eliminar la carpeta <strong id="eliminarNombre"></strong> y <u>todo su contenido</u>.</p>
        <p class="mb-2">Para confirmar, escribe <code>eliminar</code>:</p>
        <input type="text" id="eliminarConfirm" class="form-control" placeholder="eliminar" autocomplete="off">
        <small class="form-text text-muted mt-2">Esta acción no se puede deshacer.</small>
      </div>
      <div class="modal-footer">
        <button type="submit" id="btnEliminarAceptar" class="btn btn-danger" disabled>Eliminar</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<!-- Modal Renombrar CARPETA -->
<div class="modal fade" id="modalRenombrar" tabindex="-1" role="dialog" aria-labelledby="modalRenombrarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <form method="POST" class="modal-content">
        <input type="hidden" name="ruta" id="renombrarRuta">
      <div class="modal-header">
        <h5 class="modal-title" id="modalRenombrarLabel">Renombrar carpeta</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="renombrar" value="1">
        <input type="hidden" name="nombre_actual" id="nombreActual">
        <div class="form-group">
          <label for="nuevoNombre">Nuevo nombre:</label>
          <input type="text" name="nuevo_nombre" id="nuevoNombre" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Renombrar</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<!-- Modal: mover carpeta (AJAX) -->
<div class="modal fade" id="modalMoverCarpeta" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <!-- bloquea submit nativo -->
    <form class="modal-content" id="formMoverCarpeta" novalidate onsubmit="return false;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-folder-open mr-2"></i>Mover carpeta</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <!-- Origen (prefijo completo) -->
        <input type="hidden" id="moverOrigen" name="origen">

        <div class="form-group">
          <label>Carpeta a mover</label>
          <input type="text" class="form-control" id="moverNombre" readonly>
          <small class="form-text text-muted">
            Ruta: <span id="moverOrigenLabel" class="text-monospace"></span>
          </small>
        </div>

        <div class="form-group">
          <label for="moverDestino">Selecciona carpeta de destino</label>
          <select id="moverDestino" name="destino" class="form-control" required>
            <option value="">— Selecciona —</option>
          </select>
          <small class="form-text text-muted">
            Se moverá dentro de la carpeta seleccionada como subcarpeta <code id="moverPreview"></code>.
          </small>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" id="btnMoverCarpeta" class="btn btn-primary">
          <i class="fas fa-arrows-alt mr-1"></i> Mover
        </button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Compartir -->
<div class="modal fade" id="modalCompartir" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          Compartir archivo
        </h5>
        <button type="button" class="close btn btn-link" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">

        <div class="form-group mb-2">
          <label for="diasCompartir" class="mb-1">Días de vigencia (mínimo 1)</label>
          <div class="input-group">
            <input type="number" id="diasCompartir" class="form-control" min="1" step="1" placeholder="1" inputmode="numeric" oninput="return share_onDiasInput()" />
            <div class="input-group-append">
              <span class="input-group-text">día(s)</span>
            </div>
          </div>
          <small class="form-text text-muted">
            Expira el: <strong id="fechaExpiraLabel">—</strong>
          </small>
        </div>

        <div class="form-group mb-0">
          <label for="enlaceCompartido" class="mb-1">Enlace</label>
          <div class="input-group">
            <input type="text" class="form-control" id="enlaceCompartido" readonly>
            <div class="input-group-append">
              <button class="btn btn-outline-secondary" type="button" id="btnCopyLink" title="Copiar" onclick="return copiarEnlace()">
                Copiar
              </button>
            </div>
          </div>
          <small id="copyStatus" class="form-text" style="opacity:0; transition:opacity .2s;">&nbsp;</small>
          <small class="form-text text-muted" id="notaExpira">El enlace se generará con la expiración indicada.</small>
        </div>

      </div>
    </div>
  </div>
</div>
<!-- MODAL MOVER (AJAX) -->
<div class="modal fade" id="modalMover" tabindex="-1" role="dialog" aria-labelledby="modalMoverLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <form id="formMoverArchivos" class="modal-content" novalidate onsubmit="return false;">
      <div class="modal-header">
        <h5 class="modal-title" id="modalMoverLabel">Mover archivos seleccionados</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="ruta_actual" value="<?= htmlspecialchars($basePrefix) ?>">
        <input type="hidden" name="archivos_json" id="archivosJson">

        <div class="form-group">
          <label>Selecciona carpeta de destino:</label>
          <select name="nueva_ruta" id="nuevaRutaSelect" class="form-control" required>
            <option value="">— Selecciona carpeta de destino —</option>
            <?php foreach ($todasLasCarpetas as $ruta): ?>
              <?php
                $nivel = substr_count(trim($ruta, '/'), '/');
                $espacio = str_repeat('&nbsp;&nbsp;&nbsp;', $nivel);
              ?>
              <option value="<?= htmlspecialchars($ruta) ?>"><?= $espacio . htmlspecialchars($ruta) ?></option>
            <?php endforeach; ?>
            <option value="__crear__">➕ Crear nueva carpeta</option>
          </select>

          <div id="campoNuevaCarpeta" style="display:none; margin-top:10px;">
            <input type="text" name="nueva_carpeta" class="form-control" placeholder="Nombre de nueva carpeta (sin /)">
            <small class="form-text text-muted">
              Se creará bajo <code><?= htmlspecialchars($basePrefix) ?></code> (no usa “/”).
            </small>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" id="btnMoverArchivos" class="btn btn-primary">
          <i class="fas fa-arrows-alt mr-1"></i> Mover
        </button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<!-- Modal Renombrar Archivo -->
<div class="modal fade" id="modalRenombrarArchivo" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <form id="formRenombrarArchivo" class="modal-content" autocomplete="off" novalidate onsubmit="return false;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-i-cursor mr-2"></i>Renombrar archivo</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <input type="hidden" id="renameFileKey" name="key">

<div class="form-group">
  <label for="nombreActualArchivo">Nombre actual</label>
  <input type="text"
         class="form-control"
         id="nombreActualArchivo"
         name="nombre_actual"
         readonly
         style="background:#f8f9fa; color:#212529; opacity:1;">
</div>

        <div class="form-group">
          <label for="nuevoNombreArchivo">Nuevo nombre</label>
          <input type="text"
                 class="form-control"
                 id="nuevoNombreArchivo"
                 name="nombre_nuevo"
                 required>
          <small class="form-text text-muted">
            Solo cambia el nombre visible en la base de datos. El objeto en S3 mantiene su nombre encriptado.
          </small>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" id="btnRenombrarGuardar" class="btn btn-primary">
          <i class="fas fa-save mr-1"></i> Guardar
        </button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<!-- Modal visor y editor de TXT y PDF -->
<div class="modal fade" id="modalEditorArchivo" tabindex="-1" role="dialog" aria-labelledby="tituloEditor" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="tituloEditor">Archivo</h5>
        <div class="ml-auto d-flex align-items-center">
          <button type="button" class="btn btn-sm btn-outline-light mr-2" onclick="pantallaCompletaPDF()" title="Pantalla completa">
            <i class="fas fa-expand"></i>
          </button>

          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      </div>

      <div class="modal-body" id="contenidoEditor">
        <textarea id="editorTxt" class="form-control" rows="20" style="display:none;"></textarea>

        <iframe
          id="visorPdf"
          style="width:100%; height:70vh; display:none;"
          frameborder="0"
          allowfullscreen
          webkitallowfullscreen
          mozallowfullscreen
        ></iframe>
      </div>

      <div class="modal-footer">
        <button type="button" id="btnGuardarTxt" class="btn btn-primary" style="display:none;">Guardar cambios</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<!-- MODAL Metadatos -->
<div class="modal fade" id="modalMetadatos" tabindex="-1" role="dialog" aria-labelledby="metaTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="metaTitulo">Metadatos del archivo</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="cuerpoMetadatos" style="word-wrap: break-word; overflow-x: auto;">
      </div>
    </div>
  </div>
</div>

<!-- Modal: Enlaces Utiles -->
<div class="modal fade" id="modalEnlacesUtiles" tabindex="-1" role="dialog" aria-labelledby="modalEnlacesUtilesLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-md" role="document">
    <div class="modal-content">
      <div class="modal-header bg-secondary text-white">
        <h5 class="modal-title" id="modalEnlacesUtilesLabel"><i class="fas fa-link mr-2"></i> Enlaces útiles</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-0">
        <ul class="list-group list-group-flush">
          <li class="list-group-item">
             <a href="https://web.airdroid.com/?from=usercenter&lang=es-es" target="_blank">
               <i class="fab fa-android text-success mr-2"></i> AirDroid Web
             </a>
          </li>

          <li class="list-group-item">
              <a href="https://esforzados.com/s3/" target="_blank">
                <i class="fas fa-upload text-primary mr-2"></i> Subir +1GB
              </a>
          </li>
          <li class="list-group-item">
            <a href="https://mailit.click/yt.php" target="_blank">
              <i class="fab fa-youtube text-danger mr-2"></i> YouTube normal → S3
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://mailit.click/ytb.php" target="_blank">
              <i class="fab fa-youtube text-danger mr-2"></i> YouTube CC → S3
            </a>
          </li>
          
          <li class="list-group-item">
            <a href="http://esforzados.com/cpanel" target="_blank">
              <i class="fas fa-cogs text-dark mr-2"></i> cPanel Esforzados
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://drive.esforzados.com/aws.php" target="_blank">
              <i class="fas fa-qrcode text-primary mr-2"></i> Generador OTP
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://drive.esforzados.com/ec2.php" target="_blank">
              <i class="fas fa-qrcode text-primary mr-2"></i> Ec2
            </a>
          </li>
          
          <li class="list-group-item">
            <a href="https://cliente.hostgator.mx/sitios-web" target="_blank">
              <i class="fas fa-server text-info mr-2"></i> HostGator Sitios Web
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://mailit.click/" target="_blank">
              <i class="fas fa-envelope text-warning mr-2"></i> Portal Mailit
            </a>
          </li>

         <li class="list-group-item">
          <a href="https://titan.hostgator.mx/login/" target="_blank">
            <i class="fas fa-envelope text-primary mr-2"></i> Titan Mail (Webmail)
          </a>
        </li>
        
        <li class="list-group-item">
          <a href="https://demo.filestash.app/login" target="_blank">
            <i class="fas fa-hdd text-info mr-2"></i> Filestash (Explorador S3 / FTP)
          </a>
        </li>
          <li class="list-group-item">
            <a href="https://aws.amazon.com/console/" target="_blank">
              <i class="fas fa-cloud text-primary mr-2"></i> Consola AWS
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://s3.console.aws.amazon.com/s3/buckets" target="_blank">
              <i class="fas fa-folder-open text-info mr-2"></i> Buckets S3
            </a>
          </li>
          
          <li class="list-group-item">
            <a href="https://esforzados.com/AI/index.html" target="_blank">
              <i class="fas fa-robot text-info mr-2"></i> AI
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://biblia.esforzados.com/index.php" target="_blank">
              <i class="fas fa-book text-primary mr-2"></i> Concordancia
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://tiendas.esforzados.com/" target="_blank">
              <i class="fas fa-shopping-cart text-warning mr-2"></i> ShopControl
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://projects.esforzados.com/" target="_blank">
              <i class="fas fa-project-diagram text-success mr-2"></i> Projects
            </a>
          </li>
          <li class="list-group-item">
            <a href="https://esforzados.com/drone/" target="_blank">
              <i class="fas fa-project-diagram text-success mr-2"></i> SkyDrop
            </a>
          </li>
          
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Costos AWS -->
<div class="modal fade" id="modalCostosAws" tabindex="-1" role="dialog" aria-labelledby="modalCostosAwsLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="modalCostosAwsLabel">Costos AWS</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <div id="costosAwsLoading" class="text-info">
                    Consultando costos...
                </div>

                <div id="costosAwsError" class="alert alert-danger d-none mb-0"></div>

                <div id="costosAwsContenido" class="d-none" style="display:none;">
                    <div class="mb-3">
                        <div id="costosAwsMesActualTitulo" class="font-weight-bold">Mes actual</div>
                        <div id="costosAwsMesActualMonto" style="font-size: 1.25rem;">-</div>
                        <div id="costosAwsMesActualPorcentaje" class="text-info small"></div>
                    </div>

                    <div>
                        <div id="costosAwsPrevistoTitulo" class="font-weight-bold">Final de mes previsto</div>
                        <div id="costosAwsPrevistoMonto" style="font-size: 1.25rem;">-</div>
                        <div id="costosAwsPrevistoPorcentaje" class="text-warning small"></div>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Traducir -->
<div class="modal fade" id="modalTraducir" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">Traducir documento</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Archivo</label>
            <input id="traducirArchivoKey" class="form-control" readonly>
          </div>
          <div class="form-group col-md-6">
            <label>Idioma destino</label>
            <select id="traducirTarget" class="form-control">
              <!-- default -->
              <option value="es" selected>Spanish (es)</option>
            
              <option value="af">Afrikaans (af)</option>
              <option value="sq">Albanian (sq)</option>
              <option value="am">Amharic (am)</option>
              <option value="ar">Arabic (ar)</option>
              <option value="hy">Armenian (hy)</option>
              <option value="az">Azerbaijani (az)</option>
              <option value="bn">Bengali (bn)</option>
              <option value="bs">Bosnian (bs)</option>
              <option value="bg">Bulgarian (bg)</option>
              <option value="ca">Catalan (ca)</option>
              <option value="zh">Chinese (Simplified) (zh)</option>
              <option value="zh-TW">Chinese (Traditional) (zh-TW)</option>
              <option value="hr">Croatian (hr)</option>
              <option value="cs">Czech (cs)</option>
              <option value="da">Danish (da)</option>
              <option value="fa-AF">Dari (fa-AF)</option>
              <option value="nl">Dutch (nl)</option>
              <option value="en">English (en)</option>
              <option value="et">Estonian (et)</option>
              <option value="fa">Farsi (Persian) (fa)</option>
              <option value="tl">Filipino / Tagalog (tl)</option>
              <option value="fi">Finnish (fi)</option>
              <option value="fr">French (fr)</option>
              <option value="fr-CA">French (Canada) (fr-CA)</option>
              <option value="ka">Georgian (ka)</option>
              <option value="de">German (de)</option>
              <option value="el">Greek (el)</option>
              <option value="gu">Gujarati (gu)</option>
              <option value="ht">Haitian Creole (ht)</option>
              <option value="ha">Hausa (ha)</option>
              <option value="he">Hebrew (he)</option>
              <option value="hi">Hindi (hi)</option>
              <option value="hu">Hungarian (hu)</option>
              <option value="is">Icelandic (is)</option>
              <option value="id">Indonesian (id)</option>
              <option value="ga">Irish (ga)</option>
              <option value="it">Italian (it)</option>
              <option value="ja">Japanese (ja)</option>
              <option value="kn">Kannada (kn)</option>
              <option value="kk">Kazakh (kk)</option>
              <option value="ko">Korean (ko)</option>
              <option value="lv">Latvian (lv)</option>
              <option value="lt">Lithuanian (lt)</option>
              <option value="mk">Macedonian (mk)</option>
              <option value="ms">Malay (ms)</option>
              <option value="ml">Malayalam (ml)</option>
              <option value="mt">Maltese (mt)</option>
              <option value="mr">Marathi (mr)</option>
              <option value="mn">Mongolian (mn)</option>
              <option value="no">Norwegian (Bokmål) (no)</option>
              <option value="ps">Pashto (ps)</option>
              <option value="pl">Polish (pl)</option>
              <option value="pt">Portuguese (Brazil) (pt)</option>
              <option value="pt-PT">Portuguese (Portugal) (pt-PT)</option>
              <option value="pa">Punjabi (pa)</option>
              <option value="ro">Romanian (ro)</option>
              <option value="ru">Russian (ru)</option>
              <option value="sr">Serbian (sr)</option>
              <option value="si">Sinhala (si)</option>
              <option value="sk">Slovak (sk)</option>
              <option value="sl">Slovenian (sl)</option>
              <option value="so">Somali (so)</option>
              <option value="es-MX">Spanish (Mexico) (es-MX)</option>
              <option value="sw">Swahili (sw)</option>
              <option value="sv">Swedish (sv)</option>
              <option value="ta">Tamil (ta)</option>
              <option value="te">Telugu (te)</option>
              <option value="th">Thai (th)</option>
              <option value="tr">Turkish (tr)</option>
              <option value="uk">Ukrainian (uk)</option>
              <option value="ur">Urdu (ur)</option>
              <option value="uz">Uzbek (uz)</option>
              <option value="vi">Vietnamese (vi)</option>
              <option value="cy">Welsh (cy)</option>
            </select>

            <small class="form-text text-muted">El idioma origen se detecta automáticamente.</small>
          </div>
        </div>

        <div id="traducirCargando" class="alert alert-info d-none">
          Procesando… por favor espera.
        </div>

        <div id="traducirResultadoBox" class="d-none">
          <label class="mt-2">Texto traducido</label>
          <div class="d-flex mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary ml-auto" onclick="copiarTraducido()">
              Copiar
            </button>
          </div>
          <textarea id="traducirResultado" class="form-control" rows="12" readonly></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button id="btnTraducirAhora" type="button" class="btn btn-primary">Traducir</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<!-- Modal Transcribir audio/video -->
<div class="modal fade" id="modalTranscribir" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">Transcribir audio/video</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group col-md-12">
            <label>Archivo (S3 Key)</label>
            <input id="txArchivoKey" class="form-control" readonly>
            <input type="hidden" id="txFolderKey">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Nombre del trabajo</label>
            <input id="txJobName" class="form-control">
            <small class="form-text text-muted">Se llena automáticamente con el nombre del archivo sin extensión.</small>
          </div>
          <div class="form-group col-md-6">
            <label>Carpeta de salida</label>
            <input id="txFolderView" class="form-control" readonly>
            <small class="form-text text-muted">La transcripción se guardará en esta misma carpeta.</small>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Modo de idioma</label>
            <select id="txLanguageMode" class="form-control">
              <option value="specific" selected>Idioma específico</option>
              <option value="auto">Identificación automática de idiomas</option>
              <option value="auto_multi">Identificación automática de varios idiomas</option>
            </select>
          </div>
          <div id="txLanguageSpecificBox" class="form-group col-md-6">
            <label>Idioma del audio</label>
            <select id="txLanguage" class="form-control">
              <option value="es-ES" selected>Español (es-ES)</option>
              <option value="es-US">Español US (es-US)</option>
              <option value="en-US">English US (en-US)</option>
              <option value="en-GB">English UK (en-GB)</option>
              <option value="pt-BR">Português BR (pt-BR)</option>
              <option value="pt-PT">Português PT (pt-PT)</option>
              <option value="fr-FR">Français (fr-FR)</option>
              <option value="fr-CA">Français CA (fr-CA)</option>
              <option value="de-DE">Deutsch (de-DE)</option>
              <option value="it-IT">Italiano (it-IT)</option>
              <option value="ja-JP">日本語 (ja-JP)</option>
              <option value="ko-KR">한국어 (ko-KR)</option>
              <option value="zh-CN">中文(简体) (zh-CN)</option>
              <option value="zh-TW">中文(繁體) (zh-TW)</option>
              <option value="ar-SA">العربية SA (ar-SA)</option>
              <option value="ar-AE">العربية AE (ar-AE)</option>
              <option value="nl-NL">Nederlands (nl-NL)</option>
              <option value="sv-SE">Svenska (sv-SE)</option>
              <option value="fi-FI">Suomi (fi-FI)</option>
              <option value="da-DK">Dansk (da-DK)</option>
              <option value="no-NO">Norsk Bokmål (no-NO)</option>
              <option value="pl-PL">Polski (pl-PL)</option>
              <option value="tr-TR">Türkçe (tr-TR)</option>
              <option value="ru-RU">Русский (ru-RU)</option>
              <option value="uk-UA">Українська (uk-UA)</option>
              <option value="hi-IN">हिन्दी (hi-IN)</option>
              <option value="id-ID">Bahasa Indonesia (id-ID)</option>
              <option value="cs-CZ">Čeština (cs-CZ)</option>
              <option value="el-GR">Ελληνικά (el-GR)</option>
              <option value="ro-RO">Română (ro-RO)</option>
              <option value="hu-HU">Magyar (hu-HU)</option>
              <option value="sk-SK">Slovenčina (sk-SK)</option>
              <option value="sl-SI">Slovenščina (sl-SI)</option>
              <option value="bg-BG">Български (bg-BG)</option>
              <option value="et-EE">Eesti (et-EE)</option>
              <option value="lv-LV">Latviešu (lv-LV)</option>
              <option value="lt-LT">Lietuvių (lt-LT)</option>
              <option value="vi-VN">Tiếng Việt (vi-VN)</option>
              <option value="th-TH">ไทย (th-TH)</option>
            </select>
          </div>
        </div>

        <div id="txLanguageOptionsBox" class="border rounded p-3 mb-3 d-none">
          <label class="d-block mb-2">Idiomas a considerar en la detección automática (opcional)</label>
          <div class="form-row">
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="txLanguageOptions[]" value="es-US" id="txLangOptEsUs"><label class="form-check-label" for="txLangOptEsUs">es-US</label></div></div>
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="txLanguageOptions[]" value="es-ES" id="txLangOptEsEs"><label class="form-check-label" for="txLangOptEsEs">es-ES</label></div></div>
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="txLanguageOptions[]" value="en-US" id="txLangOptEnUs"><label class="form-check-label" for="txLangOptEnUs">en-US</label></div></div>
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="txLanguageOptions[]" value="en-GB" id="txLangOptEnGb"><label class="form-check-label" for="txLangOptEnGb">en-GB</label></div></div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Tipo de modelo</label>
            <select id="txModelType" class="form-control">
              <option value="general" selected>Modelo general</option>
              <option value="custom">Modelo de idioma personalizado</option>
            </select>
          </div>
          <div id="txCustomModelBox" class="form-group col-md-6 d-none">
            <label>Nombre del modelo personalizado</label>
            <input id="txCustomLanguageModelName" class="form-control" placeholder="Nombre del modelo">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-12">
            <label>Formato de archivo de subtítulos</label>
            <div class="border rounded p-2">
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="txSubtitleFormats[]" id="txSubtitleSrt" value="srt">
                <label class="form-check-label" for="txSubtitleSrt">SRT</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="txSubtitleFormats[]" id="txSubtitleVtt" value="vtt">
                <label class="form-check-label" for="txSubtitleVtt">VTT</label>
              </div>
              <small class="form-text text-muted">Si no eliges ninguno, Amazon generará el resultado JSON con el nombre del trabajo en la misma carpeta.</small>
            </div>
          </div>
        </div>

        <hr>
        <h6>Configuración avanzada</h6>

        <div class="border rounded p-3 mb-3">
          <label class="d-block mb-2">Configuración de audio</label>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="txAudioIdType" id="txAudioNone" value="none" checked>
            <label class="form-check-label" for="txAudioNone">Ninguna</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="txAudioIdType" id="txAudioChannel" value="channel">
            <label class="form-check-label" for="txAudioChannel">Identificación de canales</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="txAudioIdType" id="txAudioSpeaker" value="speaker">
            <label class="form-check-label" for="txAudioSpeaker">Partición de voces</label>
          </div>
          <div id="txSpeakerBox" class="form-group mb-0 d-none">
            <label>Cantidad máxima de voces</label>
            <input id="txMaxSpeakerLabels" type="number" min="2" max="30" value="10" class="form-control">
          </div>
        </div>

        <div class="border rounded p-3 mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="txShowAlternatives">
            <label class="form-check-label" for="txShowAlternatives">Habilitar resultados alternativos</label>
          </div>
          <div id="txAlternativesBox" class="form-group mb-0 d-none">
            <label>Cantidad máxima de resultados alternativos</label>
            <input id="txMaxAlternatives" type="number" min="2" max="10" value="2" class="form-control">
          </div>
        </div>

        <div class="border rounded p-3 mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="txEnableContentRedaction">
            <label class="form-check-label" for="txEnableContentRedaction">Redacción de información de identificación personal (PII)</label>
          </div>
          <div id="txPiiBox" class="form-group mb-0 d-none">
            <label>Tipos de entidad PII (separados por coma, opcional)</label>
            <input id="txPiiEntityTypes" class="form-control" placeholder="BANK_ACCOUNT_NUMBER,CREDIT_DEBIT_NUMBER,PHONE">
          </div>
        </div>

        <div class="border rounded p-3 mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="txToxicityDetection">
            <label class="form-check-label" for="txToxicityDetection">Detección de toxicidad</label>
          </div>
          <div id="txToxicityBox" class="d-none">
            <label class="d-block mb-2">Categorías de toxicidad (opcional)</label>
            <div class="form-row">
              <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="txToxicityCategories[]" value="ALL" id="txToxAll"><label class="form-check-label" for="txToxAll">ALL</label></div></div>
              <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="txToxicityCategories[]" value="HATE_SPEECH" id="txToxHate"><label class="form-check-label" for="txToxHate">HATE_SPEECH</label></div></div>
              <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="txToxicityCategories[]" value="HARASSMENT_OR_ABUSE" id="txToxHarassment"><label class="form-check-label" for="txToxHarassment">HARASSMENT_OR_ABUSE</label></div></div>
            </div>
          </div>
        </div>

        <div class="border rounded p-3 mb-3">
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Vocabulario personalizado</label>
              <input id="txVocabularyName" class="form-control" placeholder="Nombre del vocabulary">
            </div>
            <div class="form-group col-md-6">
              <label>Filtrado de vocabulario</label>
              <input id="txVocabularyFilterName" class="form-control" placeholder="Nombre del vocabulary filter">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6 mb-0">
              <label>Método del filtro</label>
              <select id="txVocabularyFilterMethod" class="form-control">
                <option value="remove" selected>remove</option>
                <option value="mask">mask</option>
                <option value="tag">tag</option>
              </select>
            </div>
            <div class="form-group col-md-6 mb-0">
              <div class="form-check mt-4 pt-2">
                <input class="form-check-input" type="checkbox" id="txMedicalPhi">
                <label class="form-check-label" for="txMedicalPhi">Identificación PHI (requiere Transcribe Medical)</label>
              </div>
            </div>
          </div>
        </div>

        <div id="txCargando" class="alert alert-info d-none">Creando trabajo de transcripción…</div>
        <div id="txEstadoInfo" class="alert alert-secondary d-none"></div>

        <div id="txResultadoBox" class="d-none">
          <label class="mt-2">Transcripción</label>
          <div class="d-flex mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary ml-auto" onclick="copiarTx()">Copiar</button>
          </div>
          <textarea id="txResultado" class="form-control" rows="12" readonly></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button id="btnTxIniciar" type="button" class="btn btn-primary">Crear trabajo</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<!-- Modal Textract -->
<div class="modal fade" id="modalTextract" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">Texto extraído (Textract)</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <div class="form-row">
          <div class="form-group col-md-12">
            <label>Archivo</label>
            <input id="textractArchivoKey" class="form-control" readonly>
          </div>
        </div>

        <div id="textractCargando" class="alert alert-info d-none">
          Procesando documento… por favor espera.
        </div>

        <div id="textractResultadoBox" class="d-none">
          <label class="mt-2">Texto detectado</label>
          <div class="d-flex mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary ml-auto" onclick="copiarTextract()">
              Copiar
            </button>
          </div>
          <textarea id="textractResultado" class="form-control" rows="12" readonly></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<!-- Modal Polly TTS -->
<div class="modal fade" id="modalPollyTTS" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-secondary text-white">
        <h5 class="modal-title">Leer texto (Polly TTS)</h5>
        <input type="hidden" id="pollyArchivoKey" value="">
        <button type="button" class="close text-white" data-dismiss="modal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">

        <!-- NUEVO: archivo visible -->
        <div class="form-row">
          <div class="form-group col-md-12">
            <label>Archivo origen</label>
            <input id="pollyArchivoKeyView" class="form-control" readonly>
            <small class="form-text text-muted">
              El audio se guardará en la misma carpeta del archivo origen.
            </small>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-4">
            <label>Idioma</label>
            <select id="pollyLanguage" class="form-control">
              <option value="es-ES" selected>Español (España) - es-ES</option>
              <option value="es-MX">Español (México) - es-MX</option>
              <option value="es-US">Español (EE.UU.) - es-US</option>
              <option value="en-US">Inglés (EE.UU.) - en-US</option>
              <option value="en-GB">Inglés (Reino Unido) - en-GB</option>
              <option value="pt-BR">Portugués (Brasil) - pt-BR</option>
              <option value="fr-FR">Francés (Francia) - fr-FR</option>
              <option value="de-DE">Alemán - de-DE</option>
              <option value="it-IT">Italiano - it-IT</option>
              <option value="ja-JP">Japonés - ja-JP</option>
              <option value="ko-KR">Coreano - ko-KR</option>
            </select>
            <small class="form-text text-muted">Esto filtra las voces disponibles.</small>
          </div>

          <div class="form-group col-md-4">
            <label>Voz</label>
            <select id="pollyVoice" class="form-control">
              <option value="" selected>— Cargando voces… —</option>
            </select>
            <small id="pollyEngineHelp" class="form-text text-muted"></small>
          </div>

          <div class="form-group col-md-4">
            <label>Motor</label>
            <select id="pollyEngine" class="form-control">
              <option value="neural" selected>Neural</option>
              <option value="standard">Standard</option>
            </select>
            <small class="form-text text-muted">Si la voz no soporta Neural, cambia a Standard.</small>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-8">
            <label>Texto a leer</label>
            <textarea id="pollyTexto" class="form-control" rows="6"
                      placeholder="Escribe o pega texto a leer (máx ~3000 caracteres)"></textarea>
          </div>

          <div class="form-group col-md-4">
            <label>Formato</label>
            <select id="pollyFormat" class="form-control">
              <option value="mp3" selected>MP3</option>
              <option value="ogg_vorbis">OGG Vorbis</option>
              <option value="pcm">PCM (raw)</option>
            </select>

            <label class="mt-2">Frecuencia</label>
            <select id="pollySample" class="form-control">
              <option value="22050" selected>22050 Hz</option>
              <option value="24000">24000 Hz</option>
              <option value="16000">16000 Hz</option>
              <option value="8000">8000 Hz</option>
            </select>

            <div class="custom-control custom-switch mt-3">
              <input type="checkbox" class="custom-control-input" id="pollyToS3" checked>
              <label class="custom-control-label" for="pollyToS3">
                Guardar en S3 (sobrescribe si existe)
              </label>
            </div>
            <small class="form-text text-muted">
              Se guardará en la misma carpeta que el TXT (mismo nombre encriptado, distinta extensión).
            </small>
          </div>
        </div>

        <div id="pollyCargando" class="alert alert-info d-none">Generando audio…</div>

        <div id="pollyPlayerBox" class="d-none">
          <hr>
          <audio id="pollyAudio" controls style="width:100%"></audio>
          <div class="mt-2 d-flex">
            <a id="pollyDownload" class="btn btn-outline-primary btn-sm" download="polly.mp3">Descargar</a>
            <div id="pollyS3Note" class="small text-muted ml-3"></div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button id="btnPollyGenerar" type="button" class="btn btn-primary">Generar audio</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<!-- Modal Análisis de imagen (Rekognition) -->
<div class="modal fade" id="modalRekognition" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">Análisis de imagen (Rekognition)</h5>
        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="rekogLoading" class="alert alert-info d-none">Analizando…</div>

        <div id="rekogMeta" class="small text-muted mb-2"></div>

        <div id="rekogModerationBox" class="alert alert-warning d-none">
          <strong>Contenido moderación:</strong>
          <ul id="rekogModerationList" class="mb-0"></ul>
        </div>

        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead class="thead-light">
              <tr>
                <th>Etiqueta</th>
                <th>Conf.</th>
                <th>Padres</th>
                <th>#Instancias</th>
              </tr>
            </thead>
            <tbody id="rekogLabelsBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
       
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<!-- Modal Amazon Comprehend -->
<div class="modal fade" id="modalComprehend" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="comprehendTitle">Amazon Comprehend</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" id="comprehendBody">
        <div class="text-muted">Selecciona un archivo de texto para analizarlo.</div>
      </div>
      <div class="modal-footer">
        <small class="text-muted mr-auto">Entidades · frases clave · sentimiento · PII</small>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<!-- Modal: Grabar Audio -->
<div class="modal fade" id="modalGrabarAudio" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title"><i class="fas fa-microphone"></i> Grabar audio</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <div class="form-group mb-2">
          <label for="gaNombre">Nombre del archivo (opcional)</label>
          <input id="gaNombre" type="text" class="form-control" placeholder="Ej. reunión, nota, idea... (sin extensión)">
          <small class="text-muted">Si lo dejas vacío se generará uno automático con fecha/hora.</small>
        </div>
        
        <div class="form-group">
          <label for="gaGain">Ganancia de entrada (preamp)</label>
          <input id="gaGain" type="range" min="0" max="3" step="0.05" value="1" class="w-100">
          <small class="text-muted">
            <span id="gaGainLabel">100%</span> — Si distorsiona, baja la ganancia.
          </small>
        </div>


        <div class="d-flex align-items-center justify-content-between my-2">
          <div>
            <span class="badge badge-secondary">Estado:</span>
            <span id="gaEstado" class="font-weight-bold">Listo</span>
          </div>
          <div>
            <span class="badge badge-secondary">Tiempo:</span>
            <span id="gaTimer" class="font-monospace">00:00</span>
          </div>
        </div>

        <div class="progress mb-2 d-none" style="height:6px;">
          <div id="gaProgress" class="progress-bar" role="progressbar" style="width:0%"></div>
        </div>

        <audio id="gaPreview" class="w-100 mt-2" controls style="display:none;"></audio>

        <div class="alert alert-warning mt-3 mb-0" id="gaHttpsWarn" style="display:none;">
          Para usar el micrófono necesitas cargar el sitio en <strong>HTTPS</strong> o <code>localhost</code>.
        </div>
      </div>

      <div class="modal-footer">
        <button id="gaBtnIniciar"  type="button" class="btn btn-success">
          <i class="fas fa-circle"></i> Iniciar
        </button>
        <button id="gaBtnPausar"   type="button" class="btn btn-outline-secondary" disabled>
          <i class="fas fa-pause"></i> Pausar
        </button>
        <button id="gaBtnReanudar" type="button" class="btn btn-outline-secondary d-none" disabled>
          <i class="fas fa-play"></i> Reanudar
        </button>
        <button id="gaBtnDetener"  type="button" class="btn btn-danger" disabled>
          <i class="fas fa-stop"></i> Detener
        </button>
        <button id="gaBtnGuardar"  type="button" class="btn btn-primary" disabled>
          <i class="fas fa-cloud-upload-alt"></i> Guardar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Seguridad del archivo -->
      <div class="modal fade" id="securityFileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content security-modal-content">
            <div class="modal-header">
              <h5 id="secModalTitle" class="modal-title">Seguridad del archivo</h5>
              <button type="button" class="close" aria-label="Cerrar" data-sec-close>
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <div class="modal-body">
              <div class="security-help" id="secModalText"></div>

              <div class="security-help">
                Archivo: <strong class="security-file-name" id="secFileName">—</strong>
              </div>

              <div class="security-hint-box" id="secHintBox" style="display:none;"></div>

              <div class="mb-3" id="secPasswordField">
                <label for="secPasswordInput" class="form-label">Contraseña</label>
                <div class="input-group">
                  <input type="password" id="secPasswordInput" class="form-control" autocomplete="new-password">
                  <button type="button" class="btn btn-toggle-pass" data-sec-toggle="secPasswordInput">Ver</button>
                </div>
              </div>

              <div class="mb-3" id="secConfirmField" style="display:none;">
                <label for="secConfirmInput" class="form-label">Confirmar contraseña</label>
                <div class="input-group">
                  <input type="password" id="secConfirmInput" class="form-control" autocomplete="new-password">
                  <button type="button" class="btn btn-toggle-pass" data-sec-toggle="secConfirmInput">Ver</button>
                </div>
              </div>

              <div class="mb-3" id="secHintField" style="display:none;">
                <label for="secHintInput" class="form-label">Pista para recordar</label>
                <input type="text" id="secHintInput" class="form-control" maxlength="255" placeholder="Opcional">
              </div>

              <label class="sec-check-row" id="secShowAllRow" style="display:none;">
                <input type="checkbox" id="secShowAllCheckbox"> Mostrar contraseñas
              </label>

              <div class="security-error" id="secModalError"></div>
            </div>
            <div class="modal-footer">
              <div class="security-actions w-100">
                <button type="button" class="btn btn-secondary" data-sec-cancel>Cancelar</button>
                <button type="button" class="btn btn-primary" data-sec-accept>Aceptar</button>
              </div>
            </div>
          </div>
        </div>
      </div>

</div>

<script src="js/polly.js"></script>
<script src="js/audiovideo.js"></script>



<script src="js/carpetas.js"></script>
<script src="js/archivos.js"></script>
<script src="js/file-block.js"></script>

<script src="js/actualizar-hora.js"></script>
<script src="js/storage-usage.js"></script>
<script src="js/recargarPagina.js"></script>
<script src="js/filtros.js"></script>

<script src="js/editar-txt.js"></script>

<script src="js/imagenes.js"></script>
<script src="js/aws-comprehend.js"></script>

<script src="js/mediaFloating.js"></script>

<script src="js/obtenerFiltros.js"></script>
<script src="js/pdf-pantalla-completa.js"></script>

<script src="js/soportesMediaTypes.js"></script>
<script>
  window.UPLOAD_API = "api/upload.php";
  window.DRIVE_INITIAL_ROUTE = <?= json_encode($basePrefix) ?>;
  window.rutaActual = <?= json_encode($basePrefix) ?>;
</script>
<script src="js/upload-destination.js"></script>
<script src="js/subir-dropzone.js"></script>
<script src="js/subir.js"></script>
<script src="js/ver-metadatos.js"></script>
<script src="js/ver-pdf.js"></script>

<script src="js/sincronizar.js"></script>
<script src="js/estilo.js"></script>



<!-- Cargar JS chunked -->
<script src="js/subir-chunked.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.jQuery && jQuery.fn.tooltip) {
    jQuery('[data-toggle="tooltip"]').tooltip({
      container: 'body',
      trigger: 'hover'
    });
  }
});
</script>

<script>
(function () {
  const modalId = 'modalImagenUnica';
  const modalEl = document.getElementById(modalId);
  if (!modalEl) return;

  let lastTrigger = null;

  // Guardar quién abrió el modal (para devolver foco al cerrar)
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-bs-toggle="modal"],[data-toggle="modal"]');
    if (!btn) return;

    const target = btn.getAttribute('data-bs-target') || btn.getAttribute('data-target');
    if (target === ('#' + modalId)) {
      lastTrigger = btn;
    }
  }, true);

  // Antes de ocultar: si el foco está dentro del modal, lo quitamos
  modalEl.addEventListener('hide.bs.modal', function () {
    const ae = document.activeElement;
    if (ae && modalEl.contains(ae)) ae.blur();
  });

  // Cuando ya cerró: devolver foco al que lo abrió
  modalEl.addEventListener('hidden.bs.modal', function () {
    if (lastTrigger && typeof lastTrigger.focus === 'function') {
      lastTrigger.focus();
    }
    lastTrigger = null;
  });

  // Si tu "X" sigue siendo <a href="#"> en algún lugar, evitar el salto
  document.addEventListener('click', function (e) {
    const x = e.target.closest('#' + modalId + ' .modal-x');
    if (!x) return;
    if (x.tagName === 'A') e.preventDefault();
  }, true);
})();
</script>

<script>
(function ($) {
    'use strict';

    if (typeof $ === 'undefined') {
        return;
    }

    var $modal = $('#modalCostosAws');
    if (!$modal.length) {
        return;
    }

    var xhrCostosAws = null;
    var consultaEnCurso = false;

    function el(id) {
        return $(id);
    }

    function formatearMonto(valor, moneda) {
        var numero = parseFloat(valor);
        if (isNaN(numero)) {
            return '-';
        }

        return numero.toLocaleString('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' ' + (moneda || 'USD');
    }

    function resetModal() {
        el('#costosAwsLoading')
            .removeClass('d-none')
            .css('display', 'block')
            .text('Consultando costos...');

        el('#costosAwsError')
            .addClass('d-none')
            .css('display', 'none')
            .text('');

        el('#costosAwsContenido')
            .addClass('d-none')
            .css('display', 'none');

        el('#costosAwsMesActualTitulo').text('Mes actual');
        el('#costosAwsMesActualMonto').text('-');
        el('#costosAwsMesActualPorcentaje').text('');

        el('#costosAwsPrevistoTitulo').text('Final de mes previsto');
        el('#costosAwsPrevistoMonto').text('-');
        el('#costosAwsPrevistoPorcentaje').text('');
    }

    function mostrarError(mensaje) {
        el('#costosAwsLoading')
            .addClass('d-none')
            .css('display', 'none');

        el('#costosAwsContenido')
            .addClass('d-none')
            .css('display', 'none');

        el('#costosAwsError')
            .removeClass('d-none')
            .css('display', 'block')
            .text(mensaje || 'No se pudo consultar AWS Cost Explorer.');
    }

    function mostrarContenido(resp) {
        el('#costosAwsMesActualTitulo').text(resp.mes_actual || 'Mes actual');
        el('#costosAwsMesActualMonto').text(formatearMonto(resp.costo_actual, resp.currency));
        el('#costosAwsMesActualPorcentaje').text(
            resp.porcentaje_actual !== null && typeof resp.porcentaje_actual !== 'undefined'
                ? resp.porcentaje_actual + ' %'
                : ''
        );

        el('#costosAwsPrevistoTitulo').text(resp.fin_mes_previsto || 'Final de mes previsto');
        el('#costosAwsPrevistoMonto').text(formatearMonto(resp.costo_previsto, resp.currency));
        el('#costosAwsPrevistoPorcentaje').text(
            resp.porcentaje_previsto !== null && typeof resp.porcentaje_previsto !== 'undefined'
                ? resp.porcentaje_previsto + ' %'
                : ''
        );

        el('#costosAwsLoading')
            .addClass('d-none')
            .css('display', 'none');

        el('#costosAwsError')
            .addClass('d-none')
            .css('display', 'none')
            .text('');

        el('#costosAwsContenido')
            .removeClass('d-none')
            .css('display', 'block');
    }

    function traducirError(mensaje) {
        if (!mensaje) {
            return 'No se pudo obtener la información de costos.';
        }

        var m = String(mensaje).toLowerCase();

        if (m.indexOf('timeout') !== -1) {
            return 'AWS no respondió a tiempo.';
        }

        if (
            m.indexOf('accessdenied') !== -1 ||
            m.indexOf('not authorized') !== -1 ||
            m.indexOf('unauthorized') !== -1
        ) {
            return 'No tienes permisos para consultar AWS Cost Explorer.';
        }

        if (m.indexOf('cost explorer is not enabled') !== -1) {
            return 'Cost Explorer no está habilitado en esta cuenta AWS.';
        }

        if (
            m.indexOf('sesión inválida') !== -1 ||
            m.indexOf('sesion invalida') !== -1 ||
            m.indexOf('session') !== -1
        ) {
            return 'Tu sesión no es válida o expiró.';
        }

        return mensaje;
    }

    function cargarCostosAws() {
        if (consultaEnCurso) {
            return;
        }

        consultaEnCurso = true;
        resetModal();

        if (xhrCostosAws && xhrCostosAws.readyState !== 4) {
            xhrCostosAws.abort();
        }

        xhrCostosAws = $.ajax({
            url: 'costos_aws.php',
            method: 'GET',
            dataType: 'json',
            cache: false,
            timeout: 15000,
            data: {
                _: Date.now()
            }
        });

        xhrCostosAws.done(function (resp) {
            if (!resp || typeof resp !== 'object') {
                mostrarError('La respuesta del servidor no es válida.');
                return;
            }

            if (resp.ok !== true) {
                mostrarError(traducirError(resp.error || 'No se pudo obtener la información de costos.'));
                return;
            }

            mostrarContenido(resp);
        });

        xhrCostosAws.fail(function (xhr, textStatus, errorThrown) {
            if (textStatus === 'abort') {
                return;
            }

            var mensaje = 'No se pudo consultar AWS Cost Explorer.';

            if (textStatus === 'timeout') {
                mensaje = 'AWS no respondió a tiempo.';
            } else if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                mensaje = xhr.responseJSON.error;
            } else if (xhr && xhr.responseText) {
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.error) {
                        mensaje = r.error;
                    } else {
                        mensaje = xhr.responseText;
                    }
                } catch (e) {
                    mensaje = xhr.responseText || errorThrown || mensaje;
                }
            } else if (errorThrown) {
                mensaje = errorThrown;
            }

            mostrarError(traducirError(mensaje));
        });

        xhrCostosAws.always(function () {
            consultaEnCurso = false;
        });
    }

    $modal.off('.costosaws');

    $modal.on('shown.bs.modal.costosaws', function () {
        cargarCostosAws();
    });

    $modal.on('hidden.bs.modal.costosaws', function () {
        if (xhrCostosAws && xhrCostosAws.readyState !== 4) {
            xhrCostosAws.abort();
        }

        consultaEnCurso = false;
        resetModal();
    });

})(jQuery);
</script>
</body>
</html>
