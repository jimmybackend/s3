<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Cloud Drive · Panel de herramientas</title>
  <!-- Bootstrap 5.3 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="css/styles.css">
   <link rel="icon" href="../assets/img/icono.png" type="image/x-icon">
  <script>
    // ======== Tema automático (claro/oscuro) según la configuración del usuario ========
    (()=>{
      const saved = localStorage.getItem('theme');
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      const theme = saved || (prefersDark ? 'dark' : 'light');
      document.documentElement.setAttribute('data-bs-theme', theme);
      // Escucha cambios del sistema y actualiza si el usuario no fijó un tema manualmente
      const mql = window.matchMedia('(prefers-color-scheme: dark)');
      mql.addEventListener?.('change', (e)=>{ if(!localStorage.getItem('theme')){ document.documentElement.setAttribute('data-bs-theme', e.matches?'dark':'light'); }});
      window.__toggleTheme = function(){
        const current = document.documentElement.getAttribute('data-bs-theme');
        const next = current==='dark'?'light':'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem('theme', next);
        document.getElementById('themeIcon').className = next==='dark' ? 'bi bi-moon-stars' : 'bi bi-sun';
      }
    })();
  </script>
  <style>
    /* Estilos neutrales que respetan el tema */
    .hero { 
      background: radial-gradient(1200px 400px at 10% -10%, color-mix(in oklab, var(--bs-primary), transparent 80%), transparent),
                  radial-gradient(800px 400px at 90% -20%, color-mix(in oklab, var(--bs-purple), transparent 80%), transparent);
      border-bottom: 1px solid var(--bs-border-color);
    }
    .pointer{cursor:pointer}
    .badge-ext{font-weight:600}
    .search-input{background:var(--bs-body-bg); border-color:var(--bs-border-color); color:var(--bs-body-color)}
    .search-input::placeholder{color:var(--bs-secondary-color)}
    .dropdown-menu{background:var(--bs-body-bg); color:var(--bs-body-color)}
    .dropdown-item{color:var(--bs-body-color)}
    .dropdown-item:hover{background:var(--bs-tertiary-bg)}
    .modal-content{background:var(--bs-body-bg); color:var(--bs-body-color); border:1px solid var(--bs-border-color)}
    .form-control, .form-select{background:var(--bs-body-bg); color:var(--bs-body-color); border-color:var(--bs-border-color)}
    .form-control::placeholder{color:var(--bs-secondary-color)}
    .table thead th{background:var(--bs-tertiary-bg)}
  </style>
</head>
<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on">
  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg border-bottom sticky-top" style="backdrop-filter:saturate(160%) blur(6px);">
    <div class="container-fluid">
      <a class="navbar-brand fw-bold" href="#"><img src="ellogo.png" alt="Logo Arcade" class="rounded-circle shadow mb-3" style="max-height: 40px; width: 40px; object-fit: cover;">Cloud Drive</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Alternar navegación">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav me-auto">
          <li class="nav-item"><a class="nav-link active" href="#">Inicio</a></li>
          <li class="nav-item"><a class="nav-link" href="#acciones">Acciones</a></li>
          <li class="nav-item"><a class="nav-link" href="#archivos">Archivos</a></li>
        </ul>
        <div class="d-flex align-items-center gap-2">
          <button class="btn btn-outline-secondary btn-sm" onclick="__toggleTheme()" title="Cambiar tema">
            <i id="themeIcon" class="bi bi-moon-stars"></i>
          </button>
          <a class="btn btn-primary btn-sm" href="login.php"><i class="bi bi-door-open me-1"></i> Iniciar sesión</a>
          <button class="btn btn-outline-primary btn-sm" id="btn-subir"><i class="bi bi-upload me-1"></i>Subir archivo</button>
        </div>
      </div>
    </div>
  </nav>

  <!-- Hero / resumen -->
  <section class="hero py-5">
    <div class="container">
      <div class="row align-items-center g-4">
        <div class="col-lg-7">
          <h1 class="display-6 fw-bold mb-3">Tu hub de herramientas sobre Amazon (S3 · Rekognition · Polly · Translate)</h1>
          <p class="lead mb-4 text-body">Índice de lo que podemos hacer con los archivos que el usuario sube: ver, convertir, transcribir, traducir, compartir, proteger y más. Todo listo para conectar con tus endpoints PHP.</p>
          <div class="d-flex flex-wrap gap-2">
            <span class="badge text-bg-primary">S3</span>
            <span class="badge text-bg-info">Rekognition</span>
            <span class="badge text-bg-warning">Polly</span>
            <span class="badge text-bg-success">Translate</span>
            <span class="badge text-bg-secondary">KMS/Encrypt</span>
            <span class="badge text-bg-light text-dark">ZIP</span>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <h5 class="card-title">Acciones rápidas</h5>
              <div class="row row-cols-2 g-2 mt-1">
                <div class="col"><button class="btn btn-outline-primary w-100" onclick="abrirModal('modalSubir')"><i class="bi bi-cloud-arrow-up me-1"></i>Subir</button></div>
                <div class="col"><button class="btn btn-outline-secondary w-100" onclick="refrescarLista()"><i class="bi bi-arrow-clockwise me-1"></i>Refrescar</button></div>
                <div class="col"><button class="btn btn-outline-secondary w-100" onclick="compactarZIPSeleccion()"><i class="bi bi-file-zip me-1"></i>Compactar ZIP</button></div>
                <div class="col"><button class="btn btn-outline-secondary w-100" onclick="descompactarZIPSeleccion()"><i class="bi bi-folder-symlink me-1"></i>Descompactar</button></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Sección Acciones (catálogo) -->
  <section id="acciones" class="py-5">
    <div class="container">
      <h2 class="h4 mb-4">Catálogo de acciones disponibles</h2>
      <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
        <!-- Archivo (principales) -->
        <div class="col">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex align-items-center mb-2"><i class="bi bi-file-earmark-text fs-4 me-2 text-primary"></i><h3 class="h5 mb-0">Acciones de Archivo</h3></div>
              <ul class="mb-0">
                <li>Ver imagen</li>
                <li>Ver video</li>
                <li>Escuchar audio</li>
                <li>Ver PDF</li>
                <li>Editar texto</li>
                <li>Renombrar archivo</li>
                <li>Descargar archivo</li>
                <li>Eliminar archivo</li>
                <li>Crear copia</li>
                <li>Descompactar (ZIP)</li>
                <li>Compactar ZIP</li>
              </ul>
            </div>
          </div>
        </div>
        <!-- Organización -->
        <div class="col">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex align-items-center mb-2"><i class="bi bi-folder-symlink fs-4 me-2 text-info"></i><h3 class="h5 mb-0">Acciones de Organización</h3></div>
              <ul class="mb-0">
                <li>Mover archivo</li>
                <li>Compartir archivo</li>
              </ul>
               <div class="d-flex align-items-center mb-2"><i class="bi bi-shield-lock fs-4 me-2 text-success"></i><h3 class="h5 mb-0">Acciones de Seguridad</h3></div>
              <ul class="mb-0">
                <li>Encriptar</li>
                <li>Bloquear / Desbloquear</li>
              </ul>
            </div>
          </div>
        </div>
    <!-- Seguridad -->
    <div class="col">
      <div class="card h-100">
       <div class="card-body position-relative overflow-hidden rounded-3 text-white" style="min-height: 180px; background: url('img/videollamada-bg.jpg') center / cover no-repeat;">
  <!-- Overlay oscuro para legibilidad -->
  <div class="position-absolute top-0 start-0 w-100 h-100 bg-dark opacity-50"></div>

  <!-- Contenido -->
  <div class="position-relative">
    <!-- Icono teléfono y título con enlace -->
    <div class="d-flex align-items-center mb-2">
      <i class="bi bi-telephone fs-4 me-2 text-success"></i>
      <h3 class="h5 mb-0">
        <a href="videollamadax.php" class="text-decoration-none text-white">Video Llamada</a>
      </h3>
    </div>

    <ul class="mb-2">
      <!-- Enlace directo a la página de videollamada -->
      <li><a href="videollamadax.php" class="text-white text-decoration-underline">Llamar desde la Web</a></li>
    </ul>

    <!-- Nota al usuario -->
    <div class="small text-white-50">
      Para iniciar la videollamada, <strong>contacta al administrador de la página</strong>.
    </div>
  </div>

  <!-- Stretched link para que toda la tarjeta sea clickeable hacia videollamada.php -->
  <a href="videollamadax.php" class="stretched-link" aria-label="Ir a videollamada"></a>
</div>

      </div>
    </div>

        <!-- IA / AWS / ML -->
        <div class="col">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex align-items-center mb-2"><i class="bi bi-cpu fs-4 me-2 text-warning"></i><h3 class="h5 mb-0">Procesamiento (IA / AWS / ML)</h3></div>
              <ul class="mb-0">
                <li>Extraer texto (DOC → TXT)</li>
                <li>Transcribir audio (voz → texto)</li>
                <li>Texto a voz (Polly)</li>
                <li>Traducción</li>
                <li>Análisis de imagen/documento (Rekognition)</li>
              </ul>
            </div>
          </div>
        </div>
        <!-- Sesión / Login -->
        <div class="col">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex align-items-center mb-2"><i class="bi bi-person-lock fs-4 me-2 text-danger"></i><h3 class="h5 mb-0">Sesión</h3></div>
              <p class="mb-2">Desde la barra superior puedes abrir <code>login.php</code> para iniciar sesión. Integra tu flujo de autenticación preferido (PHP + sesiones/headers o JWT).</p>
              <a class="btn btn-primary" href="login.php"><i class="bi bi-door-open me-1"></i>Ir a login.php</a>
            </div>
          </div>
        </div>
        <!-- Upload / Drag&Drop -->
        <div class="col">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex align-items-center mb-2"><i class="bi bi-cloud-arrow-up fs-4 me-2"></i><h3 class="h5 mb-0">Subida / Drag & Drop</h3></div>
              <p class="mb-2">Soporta adjuntar múltiples archivos y directorios. Conéctalo a tu endpoint PHP que sube a S3.</p>
              <button class="btn btn-outline-primary" onclick="abrirModal('modalSubir')"><i class="bi bi-plus-square-dotted me-1"></i>Abrir cargador</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Tabla de archivos -->
  <section id="archivos" class="py-4">
    <div class="container">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h4 mb-0">Archivos</h2>
        <div class="d-flex gap-2">
          <input id="search" class="form-control form-control-sm search-input" placeholder="Buscar por nombre o extensión" />
          <select id="filterExt" class="form-select form-select-sm">
            <option value="">Todas las extensiones</option>
            <option>jpg</option><option>png</option><option>mp4</option><option>mp3</option>
            <option>pdf</option><option>txt</option><option>zip</option>
          </select>
          <button class="btn btn-outline-secondary btn-sm" onclick="limpiarFiltros()"><i class="bi bi-eraser me-1"></i>Limpiar</button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle" id="tablaArchivos">
          <thead>
            <tr>
              <th style="width:30px"><input type="checkbox" id="chkAll" /></th>
              <th>Nombre</th>
              <th class="text-center">Ext</th>
              <th class="text-end">Tamaño</th>
              <th class="text-center">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <!-- Rellenado por JS -->
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Modales reutilizables -->
  <div class="modal fade" id="modalRenombrarArchivo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Renombrar / Copiar</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nombre actual</label>
            <input id="oldName" class="form-control" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Nuevo nombre</label>
            <input id="newName" class="form-control" placeholder="nuevo-nombre.ext">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="chkCopy">
            <label class="form-check-label" for="chkCopy">Crear copia en lugar de renombrar</label>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" id="btnConfirmRename"><i class="bi bi-save me-1"></i>Confirmar</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="modalMover" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Mover archivo</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Destino (ruta/prefijo S3)</label>
            <input id="inputDestino" class="form-control" placeholder="carpeta/subcarpeta/">
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" id="btnConfirmMover">Mover</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="modalCompartir" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Compartir archivo</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Enlace público / prefirmado</label>
            <input id="inputShare" class="form-control" readonly>
          </div>
          <div class="small text-body-secondary">Genera una URL prefirmada desde tu endpoint PHP y cópiala para compartir.</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button class="btn btn-primary" id="btnCopyShare"><i class="bi bi-clipboard me-1"></i>Copiar</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="modalSubir" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Subir archivos</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="border rounded-3 p-4 text-center" id="dropzone">
            <i class="bi bi-cloud-arrow-up display-6 d-block mb-2"></i>
            <p class="mb-2">Arrastra y suelta archivos aquí o</p>
            <input type="file" id="fileInput" class="form-control" multiple>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button class="btn btn-primary" id="btnEnviarSubida"><i class="bi bi-send me-1"></i>Enviar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modales IA: Polly / Translate / Rekognition -->
  <div class="modal fade" id="modalPolly" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Texto a voz (Polly)</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <textarea id="pollyText" class="form-control" rows="4" placeholder="Escribe el texto a convertir..."></textarea>
          <div class="row g-2 mt-2">
            <div class="col-6"><input id="pollyVoice" class="form-control" placeholder="Voz (ej. Lucía)"></div>
            <div class="col-6"><input id="pollyFormat" class="form-control" placeholder="Formato (mp3)"></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button><button class="btn btn-primary" id="btnRunPolly">Generar audio</button></div>
      </div>
    </div>
  </div>

 <div class="modal fade" id="modalTraducir" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Traducir texto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <textarea id="traducirText" class="form-control" rows="4" placeholder="Texto a traducir..."></textarea>
        <div class="row g-2 mt-2">
          <div class="col-6">
            <input id="traducirFrom" type="text" class="form-control" placeholder="Desde (auto)">
          </div>
          <div class="col-6">
            <input id="traducirTo" type="text" class="form-control" placeholder="A (es, en, fr)">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-primary" id="btnRunTraducir">Traducir</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalRekognition" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Análisis con Rekognition</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">Procesará el archivo seleccionado (imagen o video). Configura parámetros si es necesario.</div>
        <input id="rekognitionParams" type="text" class="form-control" placeholder='{"labels":true,"faces":false}'>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-primary" id="btnRunRekog">Analizar</button>
      </div>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container position-fixed top-0 end-0 p-3">
  <div id="appToast" class="toast border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastText">Listo.</div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
    </div>
  </div>
</div>


  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // ==================== DEMO DATA (reemplazar por datos reales desde PHP) ====================
    const archivos = [
      { nombre:'foto-playa.jpg',  key:'bucket/user/foto-playa.jpg',  ext:'jpg',  size: 1839200, ruta:'bucket/user/' },
      { nombre:'video-demo.mp4',  key:'bucket/user/video-demo.mp4',  ext:'mp4',  size: 98392000, ruta:'bucket/user/' },
      { nombre:'grabacion.mp3',   key:'bucket/user/grabacion.mp3',   ext:'mp3',  size: 4320000, ruta:'bucket/user/' },
      { nombre:'documento.pdf',   key:'bucket/user/documento.pdf',   ext:'pdf',  size: 892000,  ruta:'bucket/user/' },
      { nombre:'notas.txt',       key:'bucket/user/notas.txt',       ext:'txt',  size: 5200,    ruta:'bucket/user/' },
      { nombre:'archivos.zip',    key:'bucket/user/archivos.zip',    ext:'zip',  size: 192000,  ruta:'bucket/user/' },
    ];

    // ==================== Utils ====================
    const toast = new bootstrap.Toast(document.getElementById('appToast'));
    function showToast(msg){ document.getElementById('toastText').innerText = msg; toast.show(); }
    function bytes(n){ if(n<1024) return n+" B"; const kb=n/1024; if(kb<1024) return kb.toFixed(1)+" KB"; const mb=kb/1024; if(mb<1024) return mb.toFixed(1)+" MB"; return (mb/1024).toFixed(1)+" GB"; }
    function abrirModal(id){ const m=new bootstrap.Modal(document.getElementById(id)); m.show(); }

    // ==================== Render tabla ====================
    const tbody = document.querySelector('#tablaArchivos tbody');
    function renderTabla(){
      const q = (document.getElementById('search').value||'').toLowerCase();
      const fext = (document.getElementById('filterExt').value||'').toLowerCase();
      const rows = archivos.filter(a => (!q || a.nombre.toLowerCase().includes(q)) && (!fext || a.ext.toLowerCase()===fext));
      tbody.innerHTML = rows.map((a,idx)=>`
        <tr data-key="${a.key}" data-ext="${a.ext}" data-nombre="${a.nombre}" data-ruta="${a.ruta}">
          <td><input type="checkbox" class="chkRow"></td>
          <td class="pointer" onclick="preview(${idx})"><i class="bi bi-file-earmark me-1"></i>${a.nombre}</td>
          <td class="text-center"><span class="badge text-bg-secondary badge-ext">${a.ext}</span></td>
          <td class="text-end">${bytes(a.size)}</td>
          <td class="text-center">
            <div class="btn-group btn-group-sm">
              <button class="btn btn-outline-secondary" title="Ver" onclick="verArchivo('${a.ext}','${encodeURIComponent(a.key)}')"><i class="bi bi-eye"></i></button>
              <button class="btn btn-outline-secondary" title="Editar texto" onclick="editarTxt('${encodeURIComponent(a.key)}')"><i class="bi bi-pencil"></i></button>
              <button class="btn btn-outline-secondary" title="Descargar" onclick="descargar('${encodeURIComponent(a.key)}')"><i class="bi bi-download"></i></button>
              <button class="btn btn-outline-secondary" title="Renombrar / Copiar" onclick="abrirRenombrar('${a.nombre}')"><i class="bi bi-file-earmark-diff"></i></button>
              <button class="btn btn-outline-secondary" title="Mover" onclick="abrirModalMover('${encodeURIComponent(a.key)}')"><i class="bi bi-arrows-move"></i></button>
              <button class="btn btn-outline-secondary" title="Compartir" onclick="compartirArchivo('${encodeURIComponent(a.key)}','${a.ext}')"><i class="bi bi-share"></i></button>
              <button class="btn btn-outline-secondary" title="Seguridad" onclick="toggleLock('${encodeURIComponent(a.key)}')"><i class="bi bi-shield-lock"></i></button>
              <button class="btn btn-outline-secondary" title="Eliminar" onclick="eliminarArchivo('${encodeURIComponent(a.key)}','${encodeURIComponent(a.ruta)}')"><i class="bi bi-trash"></i></button>
              <button class="btn btn-outline-secondary" title="ZIP" onclick="zipAccion('${encodeURIComponent(a.key)}')"><i class="bi bi-file-zip"></i></button>
              <button class="btn btn-outline-secondary" title="IA" onclick="abrirIA('${encodeURIComponent(a.key)}','${a.nombre}','${a.ext}')"><i class="bi bi-cpu"></i></button>
            </div>
          </td>
        </tr>`).join('');
    }

    document.getElementById('search').addEventListener('input', renderTabla);
    document.getElementById('filterExt').addEventListener('change', renderTabla);
    document.getElementById('chkAll').addEventListener('change', (e)=>{ document.querySelectorAll('.chkRow').forEach(ch=>ch.checked=e.target.checked); });

    function limpiarFiltros(){ document.getElementById('search').value=''; document.getElementById('filterExt').value=''; renderTabla(); }

    // ==================== Acciones básicas ====================
    function preview(idx){ const a = archivos[idx]; verArchivo(a.ext, encodeURIComponent(a.key)); }
    function verArchivo(ext, key){
      key = decodeURIComponent(key);
      if(['jpg','jpeg','png','gif','webp','svg'].includes(ext)){
        showToast('Abrir visor de imagen: '+key);
      } else if(['mp4','webm','ogg'].includes(ext)){
        showToast('Abrir reproductor de video: '+key);
      } else if(['mp3','wav','m4a','aac'].includes(ext)){
        verAudioDesde(key);
      } else if(ext==='pdf'){
        verPDF(key);
      } else if(ext==='txt' || ext==='md' || ext==='csv' ){
        editarTxt(key);
      } else {
        showToast('No hay visor disponible para *.'+ext);
      }
    }

    function editarTxt(s3key){ showToast('Editar texto: '+s3key); }
    function verPDF(s3key){ showToast('Ver PDF: '+s3key); }
    function verAudioDesde(s3key){ showToast('Reproducir audio: '+s3key); }

    function descargar(key){ key = decodeURIComponent(key); const url = 'descargar.php?archivo='+encodeURIComponent(key); window.open(url, '_blank'); }

    function eliminarArchivo(key, ruta){ key = decodeURIComponent(key); ruta = decodeURIComponent(ruta); if(confirm('¿Eliminar "'+key+'"?')){ showToast('Solicitud de eliminación enviada: '+key); } }

    function abrirRenombrar(nombre){ document.getElementById('oldName').value = nombre; document.getElementById('newName').value = nombre; abrirModal('modalRenombrarArchivo'); }

    document.getElementById('btnConfirmRename').addEventListener('click', ()=>{
      const oldName = document.getElementById('oldName').value;
      const newName = document.getElementById('newName').value.trim();
      const copy    = document.getElementById('chkCopy').checked;
      if(!newName) return showToast('Escribe un nombre válido.');
      showToast((copy?'Copia creada: ':'Renombrado a: ')+newName);
      bootstrap.Modal.getInstance(document.getElementById('modalRenombrarArchivo')).hide();
    });

    // ==================== Organización ====================
    let moverKey = null;
    function abrirModalMover(s3key){ moverKey = decodeURIComponent(s3key); abrirModal('modalMover'); }

    document.getElementById('btnConfirmMover').addEventListener('click', ()=>{
      const dest = document.getElementById('inputDestino').value.trim();
      if(!dest) return showToast('Define una ruta destino.');
      showToast('Mover a: '+dest+' ( '+moverKey+' )');
      bootstrap.Modal.getInstance(document.getElementById('modalMover')).hide();
    });

    function compartirArchivo(key, ext){ key = decodeURIComponent(key); const demo = location.origin + '/share/presigned/'+ encodeURIComponent(key); document.getElementById('inputShare').value = demo; abrirModal('modalCompartir'); }
    document.getElementById('btnCopyShare').addEventListener('click', ()=>{ const el = document.getElementById('inputShare'); el.select(); document.execCommand('copy'); showToast('Enlace copiado.'); });

    // ==================== Seguridad ====================
    function setFileSecurity(action, key){ showToast('Seguridad: '+action+' => '+key); }
    function toggleLock(key){ key = decodeURIComponent(key); setFileSecurity('toggle', key); }

    // ==================== ZIP ====================
    function zipAccion(key){ key = decodeURIComponent(key); showToast('Acción ZIP sobre: '+key); }
    function compactarZIPSeleccion(){ showToast('Compactar selección a ZIP'); }
    function descompactarZIPSeleccion(){ showToast('Descompactar ZIP de la selección'); }

    // ==================== IA / AWS ====================
    function extraerTexto(s3key){ showToast('DOC2TXT para: '+s3key); }
    function transcribirAudio(s3key){ showToast('AUDIO2TXT para: '+s3key); }
    function abrirModalPolly(s3key){ abrirModal('modalPolly'); document.getElementById('pollyText').value = 'Texto ejemplo para '+s3key; }
    function abrirModalTraducir(s3key, nombre){ abrirModal('modalTraducir'); document.getElementById('traducirText').value = 'Contenido de '+(nombre||s3key); }
    function abrirModalRekognition(s3key){ abrirModal('modalRekognition'); document.getElementById('rekognitionParams').value = '{"labels":true,"moderation":true}'; }

    function abrirIA(key, nombre, ext){
      key = decodeURIComponent(key);
      if(['jpg','jpeg','png','gif','webp'].includes(ext)) return abrirModalRekognition(key);
      if(['mp3','wav','m4a','aac'].includes(ext)) return transcribirAudio(key);
      if(['txt','md','csv'].includes(ext)) return abrirModalPolly(key);
      showToast('Selecciona una acción IA para *.'+ext);
    }

    document.getElementById('btnRunPolly').addEventListener('click', ()=>{
      const text = document.getElementById('pollyText').value.trim();
      const voice = document.getElementById('pollyVoice').value.trim()||'Lucia';
      const format = document.getElementById('pollyFormat').value.trim()||'mp3';
      if(!text) return showToast('Escribe texto para convertir.');
      showToast('Polly: '+voice+' ('+format+')');
      bootstrap.Modal.getInstance(document.getElementById('modalPolly')).hide();
    });

    document.getElementById('btnRunTraducir').addEventListener('click', ()=>{
      const text = document.getElementById('traducirText').value.trim();
      const from = document.getElementById('traducirFrom').value.trim();
      const to   = document.getElementById('traducirTo').value.trim()||'es';
      if(!text) return showToast('Escribe texto a traducir.');
      showToast('Traducido a '+to);
      bootstrap.Modal.getInstance(document.getElementById('modalTraducir')).hide();
    });

    document.getElementById('btnRunRekog').addEventListener('click', ()=>{
      const params = document.getElementById('rekognitionParams').value.trim();
      showToast('Rekognition ejecutado.');
      bootstrap.Modal.getInstance(document.getElementById('modalRekognition')).hide();
    });

    // ==================== Subidas ====================
    document.getElementById('btn-subir').addEventListener('click', ()=>abrirModal('modalSubir'));
    document.getElementById('btnEnviarSubida').addEventListener('click', ()=>{
      const files = document.getElementById('fileInput').files;
      if(!files.length) return showToast('Selecciona archivos.');
      showToast(files.length+' archivo(s) enviados.');
      bootstrap.Modal.getInstance(document.getElementById('modalSubir')).hide();
    });

    // Drag & Drop
    const dropzone = document.getElementById('dropzone');
    ;['dragenter','dragover'].forEach(ev=>dropzone.addEventListener(ev, e=>{ e.preventDefault(); dropzone.classList.add('border-primary'); }));
    ;['dragleave','drop'].forEach(ev=>dropzone.addEventListener(ev, e=>{ e.preventDefault(); dropzone.classList.remove('border-primary'); }));
    dropzone.addEventListener('drop', e=>{ const files = e.dataTransfer.files; if(!files.length) return; document.getElementById('fileInput').files = files; showToast(files.length+' archivo(s) listos para subir.'); });

    // ==================== Inicio ====================
    function refrescarLista(){ renderTabla(); showToast('Lista actualizada.'); }
    renderTabla();
  </script>
</body>
</html>