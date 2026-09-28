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
    'terminal_js' => $root . '/js/so-terminal.js',
    'console_controller' => $root . '/src/Http/Controller/ServerConsoleController.php',
    'console_service' => $root . '/src/Admin/ServerConsoleService.php',
    'federation_js' => $root . '/js/so-federation.js',
    'federation_portal_js' => $root . '/js/federation-portal.js',
    'federation_admin_js' => $root . '/js/federation-os-admin.js',
    'federation_admin_renderer' => $root . '/src/View/FederationOsAdminRenderer.php',
    'federation_admin_controller' => $root . '/src/Http/Controller/FederationOsAdminController.php',
    'federation_portal_renderer' => $root . '/src/View/FederationPortalRenderer.php',
    'federation_portal_controller' => $root . '/src/Http/Controller/FederationPortalController.php',
    'moderation_renderer' => $root . '/src/View/FederationModerationPageRenderer.php',
    'server_admin_js' => $root . '/js/server-admin.js',
    'background_tasks' => $root . '/js/background-tasks.js',
    'background_controller' => $root . '/src/Http/Controller/BackgroundTaskController.php',
    'upload_center' => $root . '/js/upload-center.js',
    'updater_js' => $root . '/js/arcadecloud-updater.js',
    'update_controller' => $root . '/src/Http/Controller/ArcadeCloudUpdateController.php',
    'compute_idle_js' => $root . '/js/compute-node-idle.js',
    'compute_idle_css' => $root . '/css/compute-node-idle.css',
    'media_node' => $root . '/src/Media/MediaWorkerNodeService.php',
    'media_controller' => $root . '/src/Http/Controller/MediaProcessingController.php',
    'node_js' => $root . '/js/so-node.js',
    'share_js' => $root . '/js/so-share.js',
    'arcadelink_share' => $root . '/js/arcadelink-share.js',
    'node_controller' => $root . '/src/Http/Controller/NodeStatusController.php',
    'maintenance_service' => $root . '/src/Admin/ServerMaintenanceService.php',
    'maintenance_store' => $root . '/src/Admin/ServerMaintenanceJobStore.php',
    'maintenance_probe' => $root . '/src/Admin/ServerTaskActivityProbe.php',
    'maintenance_worker' => $root . '/src/Console/ServerMaintenanceWorkerCommand.php',
    'worker_launcher' => $root . '/src/Application/BackgroundWorkerLauncher.php',
    'sync_store' => $root . '/src/Sync/SyncJobStore.php',
    'move_store' => $root . '/src/Storage/MoveJobStore.php',
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
webOsContract(str_contains($paths['logout'], "\$this->redirect('so.php')"), 'login autenticado abre ArcadeCloud OS como experiencia principal');

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
webOsContract(str_contains($paths['js'], "mediaFile ? 'Reproducir' : 'Abrir en ventana'"), 'menú de audio/video usa Reproducir en vez de Abrir');
webOsContract(str_contains($paths['js'], "overlay.className = 'os-media-overlay is-' + kind"), 'reproductor multimedia queda superpuesto al SO');
webOsContract(str_contains($paths['js'], 'os-viewer-image'), 'imagen usa visor interno');
webOsContract(str_contains($paths['js'], 'os-viewer-frame'), 'texto/PDF pueden vivir en ventana interna');
webOsContract(!str_contains($paths['js'], "window.open("), 'apertura normal ya no crea pestañas nuevas');
webOsContract(str_contains($paths['js'], "invokeMediaProcessing(entry, 'split_video')"), 'video puede abrir procesamiento desde sus acciones');
webOsContract(str_contains($paths['js'], "invokeGlobal('abrirModalTranscribir'"), 'Transcribe se abre desde el archivo seleccionado');
webOsContract(str_contains($paths['js'], 'weekday:'), 'reloj muestra fecha además de hora');
webOsContract(str_contains($paths['css'], '#backgroundTaskButton'), 'Centro de Tareas queda sobre la barra del OS');
webOsContract(str_contains($paths['background_tasks'], 'data-bg-task-remove-selected'), 'Centro de Tareas permite eliminar selección múltiple');
webOsContract(str_contains($paths['background_tasks'], 'data-bg-task-clean-terminal'), 'Centro de Tareas permite limpiar terminadas/fallidas');
webOsContract(str_contains($paths['background_tasks'], 'bulkRemoveTasks(tasks)'), 'limpieza masiva usa un flujo único');
webOsContract(str_contains($paths['background_tasks'], "['completed', 'failed', 'cancelled']"), 'sólo tareas terminales entran en limpieza');
webOsContract(str_contains($paths['upload_center'], 'dismissTask(id)'), 'subidas terminadas también pueden limpiarse del Centro de Tareas');
webOsContract(str_contains($paths['css'], '.os-document-window'), 'ventanas de documentos tienen estilo propio');

// Escritorio simplificado y controles de ventanas.
webOsContract(!str_contains($paths['shell'], 'class="os-topbar"'), 'Web OS ya no usa barra superior');
webOsContract(str_contains($paths['shell'], '<span>Mi nodo</span>'), 'escritorio incluye Mi nodo');
webOsContract(str_contains($paths['shell'], '<span>Mis datos</span>'), 'escritorio incluye Mis datos');
webOsContract(str_contains($paths['shell'], '<span>Aplicaciones</span>'), 'escritorio incluye Aplicaciones');
$desktopNode = strpos($paths['shell'], '<span>Mi nodo</span>');
$desktopData = strpos($paths['shell'], '<span>Mis datos</span>');
$desktopApps = strpos($paths['shell'], '<span>Aplicaciones</span>');
webOsContract(
    $desktopNode !== false && $desktopData !== false && $desktopApps !== false
    && $desktopNode < $desktopData && $desktopData < $desktopApps,
    'accesos del escritorio respetan Mi nodo -> Mis datos -> Aplicaciones'
);
webOsContract(str_contains($paths['shell'], 'class="fas fa-gear"'), 'botón inferior izquierdo usa engranaje');
webOsContract(str_contains($paths['shell'], 'os-launcher-profile'), 'perfil del usuario vive dentro del lanzador');
webOsContract(str_contains($paths['shell'], 'data-task-action="minimize"'), 'barra de tareas ofrece minimizar ventana abierta');
webOsContract(str_contains($paths['shell'], 'data-task-action="maximize"'), 'barra de tareas ofrece maximizar ventana minimizada');
webOsContract(str_contains($paths['shell'], 'data-task-action="close"'), 'barra de tareas ofrece cerrar');
webOsContract(str_contains($paths['js'], "minimize.hidden = minimized"), 'tres puntos ocultan minimizar cuando la ventana ya está minimizada');
webOsContract(str_contains($paths['js'], "maximize.hidden = !minimized"), 'tres puntos ofrecen maximizar sólo cuando la ventana está minimizada');
webOsContract(str_contains($paths['js'], 'showTaskContext('), 'barra de tareas abre menú de ventana');
webOsContract(str_contains($paths['js'], "ext === 'pdf' ? 'min(820px, 72vw)'"), 'PDF abre con tamaño inicial más compacto');
webOsContract(str_contains($paths['css'], 'flex:0 0 auto'), 'controles de ventana no se encogen fuera de vista');
webOsContract(str_contains($paths['shell'], 'data-os-reload'), 'engranaje ofrece recargar ArcadeCloud OS');
webOsContract(str_contains($paths['js'], 'this.window.location.reload()'), 'Actualizar ArcadeCloud OS hace recarga completa');
webOsContract(str_contains($paths['shell'], 'data-os-about'), 'engranaje muestra Acerca de / Actualizar');
webOsContract(str_contains($paths['shell'], 'id="modalAcercaArcadeCloud"'), 'Web OS incluye diálogo Acerca de');
webOsContract(str_contains($paths['shell'], 'js/arcadecloud-updater.js'), 'Acerca de del Web OS reutiliza actualizador existente');
webOsContract(str_contains($paths['shell'], 'ARCADECLOUD_UPDATER'), 'Web OS entrega configuración del actualizador al superadmin');
webOsContract(str_contains($paths['shell'], 'server_admin_csrf'), 'Web OS prepara CSRF de actualización');
webOsContract(str_contains($paths['updater_js'], 'ARCADECLOUD_UPDATER?.csrf'), 'actualizador acepta configuración segura del Web OS');
webOsContract(str_contains($paths['update_controller'], 'isSuperAdmin()'), 'backend de actualización exige superadmin');
webOsContract(str_contains($paths['update_controller'], 'HTTP_X_SERVER_ADMIN_CSRF'), 'backend de actualización conserva CSRF');
webOsContract(str_contains($paths['shell'], 'href="logout.php"'), 'lanzador conserva cierre de sesión');
webOsContract(str_contains($paths['logout'], "\$this->redirect('index.php')"), 'cerrar sesión termina en index.php');

// Acciones de carpetas dentro del Web OS.
webOsContract(!str_contains($paths['shell'], 'class="os-folder-commandbar"'), 'Mis datos ya no muestra barra superior de acciones de carpeta');
webOsContract(str_contains($paths['shell'], 'data-current-folder-route='), 'el área interior conserva la ruta de la carpeta actual');
webOsContract(str_contains($paths['shell'], 'id="folderContextMenu"'), 'carpetas tienen menú contextual propio');
webOsContract(str_contains($paths['shell'], 'data-folder-action="sync"'), 'menú de carpeta incluye sincronizar');
webOsContract(str_contains($paths['shell'], 'data-folder-action="create-document"'), 'menú de carpeta incluye crear archivo');
webOsContract(str_contains($paths['shell'], 'data-folder-action="move"'), 'menú de carpeta incluye mover');
webOsContract(str_contains($paths['shell'], 'data-folder-action="rename"'), 'menú de carpeta incluye editar nombre');
webOsContract(str_contains($paths['shell'], 'data-folder-action="delete"'), 'menú de carpeta incluye eliminar');
webOsContract(str_contains($paths['shell'], 'data-folder-route='), 'cada carpeta visible conserva su ruta');
webOsContract(str_contains($paths['shell'], 'id="modalCrearDocumentoCarpeta"'), 'crear archivo reutiliza el flujo de documento por carpeta');
webOsContract(str_contains($paths['shell'], 'id="modalMoverCarpeta"'), 'mover carpeta reutiliza el modal existente');
webOsContract(str_contains($paths['shell'], 'id="modalRenombrar"'), 'editar carpeta reutiliza el modal de renombrar');
webOsContract(str_contains($paths['shell'], 'id="modalEliminarCarpeta"'), 'eliminar carpeta reutiliza confirmación existente');
webOsContract(str_contains($paths['shell'], 'js/move-tasks.js'), 'movimientos de carpeta mantienen tareas en segundo plano');
webOsContract(str_contains($paths['shell'], 'js/sincronizar.js'), 'sincronización reutiliza módulo existente');
webOsContract(str_contains($paths['shell'], 'js/folder-document.js'), 'creación de archivo reutiliza servicio existente');

webOsContract(str_contains($paths['folders_js'], 'bindBlankAreaContext()'), 'espacio vacío de carpeta ofrece menú contextual');
webOsContract(str_contains($paths['folders_js'], "body.addEventListener('click'"), 'toque/clic en espacio vacío abre acciones de carpeta');
webOsContract(str_contains($paths['folders_js'], "addEventListener('contextmenu'"), 'menú contextual responde también a clic derecho');
webOsContract(str_contains($paths['folders_js'], "ArcadeCloudOsShell?.clearFileSelection?.()"), 'abrir acciones de carpeta limpia selección de archivos');
webOsContract(str_contains($paths['folders_js'], "triggerSyncFolderS3"), 'acción sincronizar usa sincronización existente');
webOsContract(str_contains($paths['folders_js'], "openFolderDocumentCreator"), 'crear archivo usa creador existente');
webOsContract(str_contains($paths['folders_js'], "ArcadeFolderActions"), 'mover/editar/eliminar usan acciones compartidas');
webOsContract(str_contains($paths['folders_js'], "drive:move-task-completed"), 'movimiento refresca Mis datos al terminar');

webOsContract(str_contains($paths['folders_shared'], 'window.ArcadeFolderActions'), 'módulo clásico expone acciones de carpeta seguras al SO');
webOsContract(str_contains($paths['folder_document'], 'window.openFolderDocumentCreator'), 'creador de documento expone entrada reutilizable');
webOsContract(!str_contains($paths['sync'], "if (!btn) {\n      return this;"), 'sincronización de carpeta funciona sin botón global');
webOsContract(!str_contains($paths['css'], '.os-folder-commandbar'), 'CSS ya no conserva la barra superior retirada');

// Navegación viva, miniaturas y multimedia flotante.
webOsContract(str_contains($paths['shell'], 'id="osExplorerLive"'), 'Mis datos tiene región reemplazable sin recargar el SO');
webOsContract(str_contains($paths['shell'], "window.UPLOAD_API = 'api/upload.php'"), 'Web OS expone API OOP de subida');
webOsContract(str_contains($paths['shell'], 'class="os-explorer-pathrow"'), 'ruta de carpeta vive arriba de los controles');
webOsContract(str_contains($paths['shell'], 'data-current-folder-action="sync"'), 'barra compacta permite sincronizar la carpeta actual');
webOsContract(!str_contains($paths['shell'], 'title="Actualizar carpeta"'), 'Mis datos ya no duplica refresco y sincronización');
webOsContract(str_contains($paths['shell'], 'fa-cloud-arrow-down'), 'sincronización usa icono de nube inequívoco');
webOsContract(str_contains($paths['shell'], 'data-drive-upload-center'), 'Web OS ofrece Subir dentro de la carpeta');
webOsContract(str_contains($paths['shell'], 'data-folder-info'), 'barra compacta ofrece información de carpeta');
webOsContract(str_contains($paths['shell'], 'data-selection-action="download"'), 'selección múltiple ofrece descarga');
webOsContract(str_contains($paths['shell'], 'data-selection-action="delete"'), 'selección múltiple ofrece eliminación');
webOsContract(str_contains($paths['shell'], 'Información de carpeta'), 'panel muestra resumen de la carpeta');
webOsContract(str_contains($paths['shell'], 'Visibles (página)'), 'información incluye archivos visibles');
webOsContract(str_contains($paths['shell'], 'Bloqueados (página)'), 'información incluye archivos bloqueados');
webOsContract(str_contains($paths['css'], 'overflow-x:auto'), 'acciones y paginación permanecen en una sola línea desplazable en móvil');
webOsContract(str_contains($paths['shell'], 'js/upload-destination.js'), 'Web OS carga destino inmutable de subida');
webOsContract(str_contains($paths['shell'], 'js/upload-center.js'), 'Web OS carga centro unificado de subida');
webOsContract(!str_contains($paths['shell'], 'js/so-screenshot-paste.js'), 'pegado de screenshot ya no se ejecuta fuera del centro Subir');
webOsContract(str_contains($paths['shell'], "thumb.php?key="), 'imágenes de Mis datos reutilizan ThumbnailService');
webOsContract(str_contains($paths['shell'], 'class="os-entry-thumbnail"'), 'miniaturas se muestran en los iconos de imagen');
webOsContract(str_contains($paths['js'], 'async refreshExplorer('), 'shell actualiza sólo la ventana Mis datos');
webOsContract(str_contains($paths['js'], "current.replaceWith(next)"), 'navegación reemplaza sólo la región de Mis datos');
webOsContract(str_contains($paths['js'], "fetch(url.toString()"), 'navegación de carpetas usa solicitud parcial');
webOsContract(str_contains($paths['js'], "bindHistoryNavigation()"), 'historial atrás/adelante conserva navegación viva');
webOsContract(str_contains($paths['js'], "#explorerWindow a[data-explorer-route]"), 'sólo enlaces reales pueden navegar o refrescar Mis datos');
webOsContract(!str_contains($paths['js'], "#explorerWindow [data-explorer-route]"), 'clics sobre archivos no ascienden al contenedor de ruta');
webOsContract(str_contains($paths['folders_js'], "ArcadeCloudOsShell.refreshExplorer"), 'acciones de carpeta delegan navegación al shell');
webOsContract(str_contains($paths['folders_js'], 'rebind()'), 'acciones se vuelven a enlazar tras refrescar Mis datos');
webOsContract(str_contains($paths['css'], '.os-entry-thumbnail'), 'miniaturas tienen estilo dentro de Mis datos');
webOsContract(str_contains($paths['css'], '.os-media-overlay'), 'audio/video tienen componente flotante');
webOsContract(str_contains($paths['css'], 'z-index:20000'), 'reproductor queda por encima de ventanas y modales');

webOsContract(str_contains($paths['shell'], "'limite' => 20"), 'Mis datos pagina archivos de 20 en 20');
webOsContract(str_contains($paths['shell'], 'class="os-folder-pagination"'), 'cada carpeta muestra paginador superior');
webOsContract(str_contains($paths['shell'], 'data-explorer-page='), 'paginador conserva número de página');
webOsContract(str_contains($paths['shell'], 'Primera página'), 'paginador ofrece salto a primera página');
webOsContract(str_contains($paths['shell'], 'Última página'), 'paginador ofrece salto a última página');
webOsContract(str_contains($paths['js'], "url.searchParams.set('pagina'"), 'navegación AJAX solicita la página seleccionada');
webOsContract(str_contains($paths['js'], 'arcadePage: nextPage'), 'historial conserva página actual');
webOsContract(str_contains($paths['css'], '.os-folder-pagination'), 'paginador tiene estilo propio en el Web OS');
webOsContract(str_contains($paths['js'], "form.action = 'download_multiple.php'"), 'descarga múltiple reutiliza endpoint seguro existente');
webOsContract(str_contains($paths['js'], "'delete_multiple.php'"), 'eliminación múltiple reutiliza endpoint existente');
webOsContract(str_contains($paths['shell'], 'data-file-action="security-lock"'), 'menú de archivo permite bloquear con contraseña');
webOsContract(str_contains($paths['shell'], 'data-file-action="security-unlock"'), 'menú de archivo permite desbloquear');
webOsContract(str_contains($paths['shell'], 'data-file-action="security-relock"'), 'menú de archivo permite volver a bloquear');
webOsContract(str_contains($paths['js'], "'set_file_security.php'"), 'bloqueo reutiliza FileSecurityController');
webOsContract(str_contains($paths['js'], "'unlock_file.php'"), 'desbloqueo reutiliza FileSecurityController');
webOsContract(str_contains($paths['js'], "'relock_file.php'"), 'rebloqueo reutiliza FileSecurityController');

// Mi nodo en vivo, mantenimiento seguro y compartir completo.
webOsContract(str_contains($paths['shell'], 'js/so-node.js'), 'Web OS carga monitor de nodo en vivo');
webOsContract(str_contains($paths['shell'], 'node-status.php'), 'Mi nodo usa endpoint dedicado de estado');
webOsContract(str_contains($paths['shell'], 'data-node-field="memory_available"'), 'RAM disponible se actualiza en vivo');
webOsContract(str_contains($paths['shell'], 'data-node-memory-clear'), 'superadmin dispone de escobilla de memoria');
webOsContract(str_contains($paths['shell'], 'data-node-field="disk_used"'), 'Mi nodo muestra disco usado en vivo');
webOsContract(str_contains($paths['shell'], 'data-node-disk-clean'), 'superadmin dispone de escobilla para liberar disco');
webOsContract(str_contains($paths['shell'], 'id="nodeDiskCleanModal"'), 'limpieza de disco explica exactamente qué puede borrar');
webOsContract(str_contains($paths['node_js'], "'disk-clean'"), 'UI programa limpieza de disco como mantenimiento');
webOsContract(str_contains($paths['node_controller'], "'disk-clean'"), 'endpoint restringe limpieza de disco a acción conocida');
webOsContract(str_contains($paths['maintenance_service'], 'queueDiskCleanup'), 'servicio encola limpieza de disco');
webOsContract(str_contains($paths['maintenance_service'], 'ServerTaskActivityProbe'), 'limpieza de disco espera a las tareas activas');
webOsContract(str_contains($paths['maintenance_store'], "['memory-clear', 'disk-clean']"), 'cola sólo admite mantenimiento conocido');

webOsContract(str_contains($paths['node_js'], 'ArcadeCloudOsNodeMonitor'), 'monitor de nodo tiene módulo propio');
webOsContract(str_contains($paths['node_js'], "data-window-open=\"nodeWindow\""), 'abrir Mi nodo consulta estado actual');
webOsContract(str_contains($paths['node_js'], "queueMaintenance('memory-clear')"), 'escobilla de memoria usa mantenimiento seguro');
webOsContract(str_contains($paths['node_controller'], 'NodeCapabilityService'), 'endpoint vuelve a medir capacidad real');
webOsContract(str_contains($paths['node_controller'], 'isSuperAdmin()'), 'mantenimiento del nodo exige superadmin');
webOsContract(str_contains($paths['node_controller'], 'HTTP_X_SERVER_ADMIN_CSRF'), 'mantenimiento conserva CSRF de administración');
webOsContract(str_contains($paths['maintenance_service'], 'runServerConsole('), 'mantenimiento reutiliza helper privilegiado');
webOsContract(str_contains($paths['maintenance_service'], "'disk-clean'"), 'mantenimiento de disco usa comando allowlisted');
webOsContract(str_contains($paths['maintenance_service'], "'memory-clear'"), 'mantenimiento de memoria conserva comando allowlisted');
webOsContract(str_contains($paths['maintenance_service'], 'ServerTaskActivityProbe'), 'limpieza espera a las tareas activas');
webOsContract(str_contains($paths['maintenance_probe'], 'hasActiveJobs()'), 'sonda revisa colas persistentes antes de limpiar');
webOsContract(str_contains($paths['sync_store'], 'public function hasActiveJobs()'), 'sincronización expone estado activo a mantenimiento');
webOsContract(str_contains($paths['move_store'], 'public function hasActiveJobs()'), 'movimientos exponen estado activo a mantenimiento');
webOsContract(str_contains($paths['maintenance_store'], "'status' => 'waiting'"), 'limpieza puede quedar esperando en cola');
webOsContract(str_contains($paths['background_controller'], 'maintenance:'), 'Centro de Tareas integra mantenimiento del servidor');
webOsContract(str_contains($paths['worker_launcher'], 'launchMaintenance'), 'limpieza se ejecuta en worker desacoplado del navegador');
webOsContract(str_contains($paths['maintenance_worker'], 'ServerMaintenanceService'), 'worker de mantenimiento reutiliza el servicio OOP');

webOsContract(str_contains($paths['shell'], 'id="modalCompartir"'), 'Web OS incluye panel de compartir propio');
webOsContract(str_contains($paths['shell'], 'js/arcadelink-share.js'), 'Web OS reutiliza opciones FederationCloud del Drive clásico');
webOsContract(str_contains($paths['shell'], 'js/so-share.js'), 'Web OS carga controlador de compartir sin prompts');
webOsContract(str_contains($paths['share_js'], "generar_token.php"), 'panel genera enlace directo con vigencia');
webOsContract(str_contains($paths['arcadelink_share'], 'prepareSingle('), 'ArcadeLink expone contexto reutilizable para el SO');
webOsContract(str_contains($paths['arcadelink_share'], 'federationcloud/create.php'), 'panel conserva publicación FederationCloud');

// Autoapagado del nodo grande por inactividad.
webOsContract(str_contains($paths['drive'], 'js/compute-node-idle.js'), 'Drive clásico vigila inactividad del nodo grande');
webOsContract(str_contains($paths['shell'], 'js/compute-node-idle.js'), 'Web OS vigila inactividad del nodo grande');
webOsContract(str_contains($paths['compute_idle_js'], "this.pollMs = 5000"), 'estado de inactividad se consulta periódicamente');
webOsContract(str_contains($paths['compute_idle_js'], "node_activity: '1'"), 'actividad real reinicia contador del nodo');
webOsContract(str_contains($paths['compute_idle_js'], "node_shutdown_now: '1'"), 'aviso permite solicitar apagado seguro');
webOsContract(str_contains($paths['compute_idle_js'], 'Han pasado 10 minutos sin actividad'), 'aviso explica umbral de diez minutos');
webOsContract(str_contains($paths['compute_idle_js'], 'data-compute-idle-count'), 'aviso muestra cuenta regresiva');
webOsContract(str_contains($paths['media_node'], 'IDLE_WARNING_SECONDS = 30'), 'backend reserva treinta segundos para advertencia');
webOsContract(str_contains($paths['media_node'], "?: 600"), 'backend usa diez minutos de inactividad por defecto');
webOsContract(str_contains($paths['media_node'], 'touchInteractiveActivity'), 'actividad de s3/so se registra en la sesión EC2');
webOsContract(str_contains($paths['media_node'], 'requestIdleStop'), 'apagado interactivo pasa por servicio del nodo');
webOsContract(str_contains($paths['media_node'], "(\$idle['warning'] ?? false) !== true"), 'StopInstances interactivo sólo se permite durante aviso de inactividad');
webOsContract(str_contains($paths['media_controller'], "queryString('idle_status')"), 'endpoint expone estado de inactividad');
webOsContract(str_contains($paths['media_controller'], "postString('node_activity')"), 'endpoint acepta heartbeat autenticado');
webOsContract(str_contains($paths['media_controller'], "postString('node_shutdown_now')"), 'endpoint acepta apagado desde advertencia');
webOsContract(str_contains($paths['compute_idle_css'], '.compute-idle-warning'), 'aviso de apagado tiene UI compartida');

// Terminal restringida y nomenclatura única Mis datos.
webOsContract(!str_contains($paths['shell'], '>Mis documentos<'), 'Web OS ya no muestra Mis documentos');
webOsContract(!str_contains($paths['shell'], '> Mis archivos</button>'), 'Web OS ya no muestra Mis archivos');
webOsContract(!str_contains($paths['shell'], '>Explorador<'), 'Web OS ya no muestra Explorador como nombre visible');
webOsContract(str_contains($paths['shell'], 'data-window-title="Mis datos"'), 'ventana de archivos se llama Mis datos');
webOsContract(str_contains($paths['shell'], '<strong>Mis datos</strong>'), 'Aplicaciones usa el nombre Mis datos');
webOsContract(str_contains($paths['shell'], 'id="terminalWindow"'), 'SO incluye ventana Terminal para superadmin');
webOsContract(str_contains($paths['shell'], "if (\$isSuperAdmin): ?>\n    <section class=\"os-window os-terminal-window\""), 'Terminal se renderiza sólo dentro del guard superadmin');
webOsContract(str_contains($paths['shell'], 'data-open-terminal'), 'Aplicaciones puede abrir Terminal');
webOsContract(str_contains($paths['shell'], 'ARCADECLOUD_OS_SERVER_CONSOLE'), 'SO expone configuración temporal de terminal');
webOsContract(str_contains($paths['shell'], 'server-console.php'), 'Terminal reutiliza endpoint restringido existente');
webOsContract(str_contains($paths['terminal_js'], 'data-terminal-command'), 'Terminal renderiza opciones ejecutables');
webOsContract(str_contains($paths['terminal_js'], "command === 'memory-clear'"), 'mantenimiento conserva confirmación reforzada');
webOsContract(str_contains($paths['terminal_js'], "body.append('csrf'"), 'Terminal envía CSRF al endpoint');
webOsContract(str_contains($paths['console_controller'], 'isSuperAdmin()'), 'endpoint conserva autorización superadmin');
webOsContract(str_contains($paths['console_service'], 'Comando no permitido'), 'servidor mantiene allowlist exacta');
webOsContract(!str_contains($paths['terminal_js'], 'shell_exec('), 'Terminal del SO no ejecuta shell directa');
webOsContract(!str_contains($paths['terminal_js'], 'exec('), 'Terminal del SO no usa exec local');

// FederationCloud como aplicación nativa del Web OS.
webOsContract(str_contains($paths['shell'], 'data-window-open="federationWindow"'), 'Aplicaciones abre FederationCloud como ventana');
webOsContract(str_contains($paths['shell'], 'id="federationWindow"'), 'Web OS incluye ventana FederationCloud');
webOsContract(str_contains($paths['shell'], 'id="federationFrame"'), 'FederationCloud se ejecuta dentro de la ventana');
webOsContract(str_contains($paths['shell'], 'data-federation-view="search"'), 'FederationCloud ofrece buscar');
webOsContract(str_contains($paths['shell'], 'data-federation-view="requests"'), 'FederationCloud ofrece solicitudes');
webOsContract(str_contains($paths['shell'], 'data-federation-view="shares"'), 'FederationCloud ofrece compartidos');
webOsContract(str_contains($paths['shell'], 'data-federation-view="replicas"'), 'FederationCloud ofrece réplicas');
webOsContract(str_contains($paths['shell'], 'data-federation-view="about"'), 'FederationCloud ofrece Acerca de');
webOsContract(str_contains($paths['shell'], 'data-federation-view="moderation"'), 'superadmin tiene acceso directo a moderación');
webOsContract(str_contains($paths['shell'], 'federationcloud/portal.php?embed=1'), 'portal FederationCloud usa modo embebido');
webOsContract(str_contains($paths['federation_js'], "federationcloud/os-admin.php?embed=1"), 'Acerca de carga administración dentro de la aplicación');
webOsContract(str_contains($paths['federation_js'], "federationcloud/moderation.php?embed=1"), 'moderación permanece dentro de la aplicación');
webOsContract(str_contains($paths['federation_portal_js'], "this.embedded"), 'acciones de recursos detectan el modo Web OS');
webOsContract(str_contains($paths['federation_portal_js'], 'Abrir / descargar'), 'catálogo ofrece abrir o descargar recursos');
webOsContract(str_contains($paths['css'], '.os-federation-window'), 'ventana FederationCloud tiene estilo propio');

$launcherStart = strpos($paths['shell'], '<div class="os-launcher" id="osLauncher"');
$launcherEnd = $launcherStart === false ? false : strpos($paths['shell'], '<div class="os-task-context"', $launcherStart);
$launcherMarkup = ($launcherStart !== false && $launcherEnd !== false)
    ? substr($paths['shell'], $launcherStart, $launcherEnd - $launcherStart)
    : '';
webOsContract($launcherMarkup !== '', 'lanzador inferior se puede inspeccionar');
webOsContract(!str_contains($launcherMarkup, 'terminalWindow'), 'Terminal ya no aparece en el menú inferior izquierdo');
webOsContract(str_contains($paths['shell'], '<strong>Terminal</strong>'), 'Terminal aparece dentro de Aplicaciones');

webOsContract(str_contains($paths['federation_portal_controller'], "queryString('embed')"), 'portal soporta modo embebido desde Controller');
webOsContract(str_contains($paths['federation_portal_renderer'], 'federation-portal-embedded'), 'portal embebido elimina chrome duplicado');
webOsContract(str_contains($paths['federation_admin_controller'], 'federation_provider_csrf'), 'Acerca de prepara CSRF de solicitudes de proveedores');
webOsContract(str_contains($paths['federation_admin_controller'], 'server_admin_csrf'), 'Acerca de prepara CSRF de configuración del servidor');
webOsContract(str_contains($paths['federation_admin_renderer'], 'federationAboutNode'), 'Acerca de muestra datos del nodo');
webOsContract(str_contains($paths['federation_admin_renderer'], 'federationAboutPeerList'), 'Acerca de muestra nodos conectados');
webOsContract(str_contains($paths['federation_admin_renderer'], 'federationProviderPendingList'), 'Acerca de muestra solicitudes de proveedor');
webOsContract(str_contains($paths['federation_admin_renderer'], 'modalServerAdmin'), 'Acerca de integra configuración del servidor');
webOsContract(str_contains($paths['federation_admin_renderer'], 'federationAboutModeration'), 'Acerca de integra moderación');
webOsContract(str_contains($paths['federation_admin_js'], "provider-admin.php"), 'solicitudes reutilizan endpoint de proveedores');
webOsContract(str_contains($paths['federation_admin_js'], "moderation-api.php"), 'Acerca de consulta moderación existente');
webOsContract(str_contains($paths['server_admin_js'], 'dataset.endpoint'), 'configuración del servidor acepta endpoint reutilizable');
webOsContract(str_contains($paths['moderation_renderer'], 'federation-moderation-embedded'), 'moderación soporta modo embebido');

echo "WEB_OS_CONTRACT_OK\n";
