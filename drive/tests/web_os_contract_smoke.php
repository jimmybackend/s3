<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$paths = [
    'shell' => $root . '/so.php',
    'css' => $root . '/css/so.css',
    'js' => $root . '/js/so.js',
    'capability' => $root . '/src/System/NodeCapabilityService.php',
    'drive' => $root . '/s3.php',
    'logout' => $root . '/src/Http/Controller/AuthController.php',
    'folders_js' => $root . '/js/so-folders.js',
    'folders_shared' => $root . '/js/carpetas.js',
    'folder_document' => $root . '/js/folder-document.js',
    'sync' => $root . '/js/sincronizar.js',
];

foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: falta {$name}: {$path}\n");
        exit(1);
    }
    $paths[$name] = (string)file_get_contents($path);
}

function webOsContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

webOsContract(str_contains($paths['drive'], 'href="so.php"'), 'Drive clásico enlaza ArcadeCloud OS');
webOsContract(str_contains($paths['drive'], 'ArcadeCloud OS'), 'enlace del menú tiene nombre estable');

webOsContract(str_contains($paths['shell'], "requireAuthenticated('index.php')"), 'so.php exige sesión autenticada');
webOsContract(str_contains($paths['shell'], 'ensureRoot($userId)'), 'so.php provisiona únicamente la raíz del usuario');
webOsContract(str_contains($paths['shell'], 'normalizeForUser('), 'ruta solicitada se limita con UserStoragePath');
webOsContract(str_contains($paths['shell'], 'listHierarchyRows($userId)'), 'carpetas se consultan con user_id autenticado');
webOsContract(str_contains($paths['shell'], 'fileListService()->load($userId, $currentRoute'), 'archivos se listan por usuario y ruta normalizada');
webOsContract(!str_contains($paths['shell'], 'listObjects'), 'Web OS no lista S3 para navegar');
webOsContract(!str_contains($paths['shell'], 'Data2/'), 'Web OS no presenta raíces físicas ajenas hardcodeadas');
webOsContract(str_contains($paths['shell'], '>Mi nodo<'), 'interfaz usa Mi nodo');
webOsContract(str_contains($paths['shell'], 'NodeCapabilityService'), 'Mi nodo usa detector de capacidad');
webOsContract(str_contains($paths['shell'], 'FileViewHelper::isLocked($row)'), 'archivos protegidos no se abren como normales');
webOsContract(str_contains($paths['shell'], 'Drive clásico'), 'existe retorno explícito al Drive clásico');

webOsContract(str_contains($paths['capability'], '/proc/meminfo'), 'capacidad lee RAM real');
webOsContract(str_contains($paths['capability'], '/sys/devices/system/cpu/online'), 'capacidad lee CPU real');
webOsContract(str_contains($paths['capability'], 'disk_free_space'), 'capacidad mide disco real');
webOsContract(str_contains($paths['capability'], 'X-aws-ec2-metadata-token-ttl-seconds'), 'EC2 se consulta mediante IMDSv2');
webOsContract(str_contains($paths['capability'], "'requires_docker'"), 'perfil puede exigir Docker');
webOsContract(str_contains($paths['capability'], "'requires_gpu'"), 'perfil puede exigir GPU');
webOsContract(str_contains($paths['capability'], "'commands'"), 'perfil puede exigir dependencias ejecutables');
webOsContract(!str_contains($paths['capability'], 'shell_exec('), 'detector no ejecuta shell arbitraria');

webOsContract(str_contains($paths['js'], 'data-window-open'), 'shell abre aplicaciones como ventanas');
webOsContract(str_contains($paths['js'], "addEventListener('contextmenu'"), 'archivos tienen menú contextual');
webOsContract(str_contains($paths['js'], 'data-window-drag-handle'), 'ventanas son arrastrables en escritorio');
webOsContract(str_contains($paths['css'], '.os-taskbar'), 'existe barra de tareas');
webOsContract(str_contains($paths['css'], '@media (max-width:800px)'), 'shell conserva experiencia móvil');

// Workbench dentro de so.php.
webOsContract(str_contains($paths['shell'], 'data-file-action="textract"'), 'acciones de archivo incluyen Textract');
webOsContract(str_contains($paths['shell'], 'data-file-action="transcribe"'), 'acciones de archivo incluyen Transcribe');
webOsContract(str_contains($paths['shell'], 'data-file-action="polly"'), 'acciones de archivo incluyen Polly');
webOsContract(str_contains($paths['shell'], 'data-file-action="translate"'), 'acciones de archivo incluyen Translate');
webOsContract(str_contains($paths['shell'], 'data-file-action="rekognition"'), 'acciones de archivo incluyen Rekognition');
webOsContract(str_contains($paths['shell'], 'data-file-action="comprehend"'), 'acciones de archivo incluyen Comprehend');
webOsContract(str_contains($paths['shell'], 'id="modalMediaSplit"'), 'procesamiento multimedia conserva su configuración dentro del OS');
webOsContract(str_contains($paths['shell'], 'id="modalTranscribir"'), 'Transcribe conserva su diálogo de configuración dentro del OS');
webOsContract(str_contains($paths['shell'], 'id="modalPollyTTS"'), 'Polly conserva su diálogo de configuración dentro del OS');
webOsContract(str_contains($paths['shell'], 'js/background-tasks.js'), 'OS carga Centro unificado de Tareas');
webOsContract(str_contains($paths['shell'], 'js/media-processing.js'), 'OS reutiliza procesamiento multimedia validado');
webOsContract(str_contains($paths['shell'], 'js/aws-comprehend.js'), 'OS reutiliza módulo Comprehend');
webOsContract(str_contains($paths['shell'], 'Disco usado'), 'Mi nodo muestra espacio de disco usado');
webOsContract(str_contains($paths['shell'], 'Disco total'), 'Mi nodo muestra tamaño total de disco');

webOsContract(str_contains($paths['js'], 'createViewerWindow('), 'archivos se abren en ventanas del Web OS');
webOsContract(str_contains($paths['js'], 'openMediaOverlay('), 'audio/video usan reproductor flotante');
webOsContract(str_contains($paths['js'], "overlay.className = 'os-media-overlay is-' + kind"), 'reproductor multimedia queda superpuesto al SO');
webOsContract(str_contains($paths['js'], 'os-viewer-image'), 'imagen usa visor interno');
webOsContract(str_contains($paths['js'], 'os-viewer-frame'), 'texto/PDF pueden vivir en ventana interna');
webOsContract(!str_contains($paths['js'], "window.open("), 'apertura normal ya no crea pestañas nuevas');
webOsContract(str_contains($paths['js'], "invokeMediaProcessing(entry, 'split_video')"), 'video puede abrir procesamiento desde sus acciones');
webOsContract(str_contains($paths['js'], "invokeGlobal('abrirModalTranscribir'"), 'Transcribe se abre desde el archivo seleccionado');
webOsContract(str_contains($paths['js'], 'weekday:'), 'reloj muestra fecha además de hora');
webOsContract(str_contains($paths['css'], '#backgroundTaskButton'), 'Centro de Tareas queda sobre la barra del OS');
webOsContract(str_contains($paths['css'], '.os-document-window'), 'ventanas de documentos tienen estilo propio');

// Escritorio simplificado y controles de ventanas.
webOsContract(!str_contains($paths['shell'], 'class="os-topbar"'), 'Web OS ya no usa barra superior');
webOsContract(str_contains($paths['shell'], '<span>Mi nodo</span>'), 'escritorio incluye Mi nodo');
webOsContract(str_contains($paths['shell'], '<span>Mis documentos</span>'), 'escritorio incluye Mis documentos');
webOsContract(str_contains($paths['shell'], '<span>Aplicaciones</span>'), 'escritorio incluye Aplicaciones');
$desktopNode = strpos($paths['shell'], '<span>Mi nodo</span>');
$desktopDocs = strpos($paths['shell'], '<span>Mis documentos</span>');
$desktopApps = strpos($paths['shell'], '<span>Aplicaciones</span>');
webOsContract(
    $desktopNode !== false && $desktopDocs !== false && $desktopApps !== false
    && $desktopNode < $desktopDocs && $desktopDocs < $desktopApps,
    'accesos del escritorio respetan Mi nodo -> Mis documentos -> Aplicaciones'
);
webOsContract(str_contains($paths['shell'], 'class="fas fa-gear"'), 'botón inferior izquierdo usa engranaje');
webOsContract(str_contains($paths['shell'], 'os-launcher-profile'), 'perfil del usuario vive dentro del lanzador');
webOsContract(str_contains($paths['shell'], 'data-task-action="maximize"'), 'barra de tareas ofrece maximizar/restaurar');
webOsContract(str_contains($paths['shell'], 'data-task-action="close"'), 'barra de tareas ofrece cerrar');
webOsContract(str_contains($paths['js'], 'showTaskContext('), 'barra de tareas abre menú de ventana');
webOsContract(str_contains($paths['js'], "ext === 'pdf' ? 'min(820px, 72vw)'"), 'PDF abre con tamaño inicial más compacto');
webOsContract(str_contains($paths['css'], 'flex:0 0 auto'), 'controles de ventana no se encogen fuera de vista');
webOsContract(str_contains($paths['shell'], 'href="logout.php"'), 'lanzador conserva cierre de sesión');
webOsContract(str_contains($paths['logout'], "\$this->redirect('index.php')"), 'cerrar sesión termina en index.php');

// Acciones de carpetas dentro del Web OS.
webOsContract(str_contains($paths['shell'], 'class="os-folder-commandbar"'), 'Explorador tiene barra de acciones de carpeta');
webOsContract(str_contains($paths['shell'], 'data-current-folder-action="sync"'), 'barra de carpeta incluye sincronizar');
webOsContract(str_contains($paths['shell'], 'data-current-folder-action="create-document"'), 'barra de carpeta incluye crear archivo');
webOsContract(str_contains($paths['shell'], 'data-current-folder-action="move"'), 'barra de carpeta incluye mover');
webOsContract(str_contains($paths['shell'], 'data-current-folder-action="rename"'), 'barra de carpeta incluye editar nombre');
webOsContract(str_contains($paths['shell'], 'data-current-folder-action="delete"'), 'barra de carpeta incluye eliminar');
webOsContract(str_contains($paths['shell'], 'id="folderContextMenu"'), 'carpetas tienen menú contextual propio');
webOsContract(str_contains($paths['shell'], 'data-folder-route='), 'cada carpeta visible conserva su ruta');
webOsContract(str_contains($paths['shell'], 'id="modalCrearDocumentoCarpeta"'), 'crear archivo reutiliza el flujo de documento por carpeta');
webOsContract(str_contains($paths['shell'], 'id="modalMoverCarpeta"'), 'mover carpeta reutiliza el modal existente');
webOsContract(str_contains($paths['shell'], 'id="modalRenombrar"'), 'editar carpeta reutiliza el modal de renombrar');
webOsContract(str_contains($paths['shell'], 'id="modalEliminarCarpeta"'), 'eliminar carpeta reutiliza confirmación existente');
webOsContract(str_contains($paths['shell'], 'js/move-tasks.js'), 'movimientos de carpeta mantienen tareas en segundo plano');
webOsContract(str_contains($paths['shell'], 'js/sincronizar.js'), 'sincronización reutiliza módulo existente');
webOsContract(str_contains($paths['shell'], 'js/folder-document.js'), 'creación de archivo reutiliza servicio existente');

webOsContract(str_contains($paths['folders_js'], 'bindBlankAreaContext()'), 'espacio vacío de carpeta ofrece menú contextual');
webOsContract(str_contains($paths['folders_js'], "addEventListener('contextmenu'"), 'menú contextual responde a clic derecho');
webOsContract(str_contains($paths['folders_js'], "triggerSyncFolderS3"), 'acción sincronizar usa sincronización existente');
webOsContract(str_contains($paths['folders_js'], "openFolderDocumentCreator"), 'crear archivo usa creador existente');
webOsContract(str_contains($paths['folders_js'], "ArcadeFolderActions"), 'mover/editar/eliminar usan acciones compartidas');
webOsContract(str_contains($paths['folders_js'], "drive:move-task-completed"), 'movimiento refresca el Explorador al terminar');

webOsContract(str_contains($paths['folders_shared'], 'window.ArcadeFolderActions'), 'módulo clásico expone acciones de carpeta seguras al SO');
webOsContract(str_contains($paths['folder_document'], 'window.openFolderDocumentCreator'), 'creador de documento expone entrada reutilizable');
webOsContract(!str_contains($paths['sync'], "if (!btn) {\n      return this;"), 'sincronización de carpeta funciona sin botón global');
webOsContract(str_contains($paths['css'], '.os-folder-commandbar'), 'barra de acciones de carpeta tiene estilo Web OS');

// Navegación viva, miniaturas y multimedia flotante.
webOsContract(str_contains($paths['shell'], 'id="osExplorerLive"'), 'Explorador tiene región reemplazable sin recargar el SO');
webOsContract(str_contains($paths['shell'], "thumb.php?key="), 'imágenes del Explorador reutilizan ThumbnailService');
webOsContract(str_contains($paths['shell'], 'class="os-entry-thumbnail"'), 'miniaturas se muestran en los iconos de imagen');
webOsContract(str_contains($paths['js'], 'async refreshExplorer('), 'shell actualiza sólo la ventana Explorador');
webOsContract(str_contains($paths['js'], "current.replaceWith(next)"), 'navegación reemplaza sólo la región del Explorador');
webOsContract(str_contains($paths['js'], "fetch(url.toString()"), 'navegación de carpetas usa solicitud parcial');
webOsContract(str_contains($paths['js'], "bindHistoryNavigation()"), 'historial atrás/adelante conserva navegación viva');
webOsContract(str_contains($paths['folders_js'], "ArcadeCloudOsShell.refreshExplorer"), 'acciones de carpeta delegan navegación al shell');
webOsContract(str_contains($paths['folders_js'], 'rebind()'), 'acciones se vuelven a enlazar tras refrescar Explorador');
webOsContract(str_contains($paths['css'], '.os-entry-thumbnail'), 'miniaturas tienen estilo dentro del Explorador');
webOsContract(str_contains($paths['css'], '.os-media-overlay'), 'audio/video tienen componente flotante');
webOsContract(str_contains($paths['css'], 'z-index:20000'), 'reproductor queda por encima de ventanas y modales');

echo "WEB_OS_CONTRACT_OK\n";
