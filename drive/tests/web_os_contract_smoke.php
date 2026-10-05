<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$paths = [
    'shell' => $root . '/so.php',
    'css' => $root . '/css/so.css',
    'js' => $root . '/js/so.js',
    'file_apps_js' => $root . '/js/file-applications.js',
    'window_manager_js' => $root . '/js/os-window-manager.js',
    'desktop_shell_js' => $root . '/js/desktop-shell.js',
    'page_task_manager_js' => $root . '/js/page-task-manager.js',
    'appearance_js' => $root . '/js/so-appearance.js',
    'preferences_endpoint' => $root . '/os-preferences.php',
    'preferences_repository' => $root . '/src/Security/UserOsPreferencesRepository.php',
    'preferences_node_resolver' => $root . '/src/Security/OsPreferenceNodeResolver.php',
    'preferences_schema' => $root . '/src/Security/UserOsPreferencesSchemaService.php',
    'updater_service' => $root . '/src/Admin/ArcadeCloudUpdaterService.php',
    'capability' => $root . '/src/System/NodeCapabilityService.php',
    'drive' => $root . '/s3.php',
    'logout' => $root . '/src/Http/Controller/AuthController.php',
    'folders_js' => $root . '/js/so-folders.js',
    'clipboard_js' => $root . '/js/so-clipboard.js',
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
    'federation_admin_css' => $root . '/css/federation-os-admin.css',
    'system_panel_css' => $root . '/css/os-system-panel.css',
    'ec2_page' => $root . '/ec2.php',
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
    'power_js' => $root . '/js/so-power.js',
    'power_endpoint' => $root . '/fastdrive-power.php',
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
    'security_js' => $root . '/js/file-security.js',
    'classic_files_js' => $root . '/js/archivos.js',
    'search_js' => $root . '/js/so-search.js',
    'search_controller' => $root . '/src/Http/Controller/FileSearchController.php',
    'search_service' => $root . '/src/Application/FileSearchService.php',
    'ai_search_service' => $root . '/src/Application/AiFileSearchService.php',
    'file_list_service' => $root . '/src/Application/FileListService.php',
    'folder_query_service' => $root . '/src/Application/FolderQueryService.php',
    'folder_repository' => $root . '/src/Storage/FolderRepository.php',
    'folder_suggestions' => $root . '/folder-suggestions.php',
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
webOsContract(str_contains($paths['shell'], 'data-explorer-visible-route=') && str_contains($paths['shell'], 'data-explorer-breadcrumbs='), 'Explorer separa ruta visible de catálogo y Prefix físico interno');
webOsContract(str_contains($paths['folder_query_service'], 'breadcrumbsForUser(') && str_contains($paths['folder_query_service'], "'label' =>") && str_contains($paths['folder_query_service'], "'route' =>"), 'breadcrumbs conservan label visible y route físico por separado');
webOsContract(str_contains($paths['window_manager_js'], 'this.visibleRoute') && str_contains($paths['window_manager_js'], 'this.breadcrumbs'), 'WindowManager muestra rutas de catálogo sin sustituir la ruta operativa');
webOsContract(str_contains($paths['window_manager_js'], 'button.textContent = label') && !str_contains($paths['window_manager_js'], 'button.textContent = route; this.suggestions.append'), 'autocompletado no imprime Prefix físico al usuario');
webOsContract(str_contains($paths['folder_repository'], 'OR Nombre LIKE') && str_contains($paths['folder_suggestions'], "'label' => \$app->folderQueryService()->displayPathForUser"), 'sugerencias buscan Nombre de catálogo y devuelven label visible');
webOsContract(str_contains($paths['window_manager_js'], 'sourceLabel: this.visibleRoute') && str_contains($paths['window_manager_js'], 'destinationLabel'), 'confirmación drag/drop usa rutas visibles y mantiene rutas físicas para ejecutar');
webOsContract(str_contains($paths['window_manager_js'], 'DESKTOP_PERSISTENCE_BREAKPOINT = 1180') && str_contains($paths['window_manager_js'], 'usesDesktopPersistence()'), 'posición y tamaño persistentes se limitan a vista de computadora');
webOsContract(str_contains($paths['window_manager_js'], 'left: rect.left') && str_contains($paths['window_manager_js'], 'top: rect.top') && str_contains($paths['window_manager_js'], 'persistCurrentGeometry(record)'), 'gestor guarda posición y tamaño reales al mover, redimensionar o cerrar');
webOsContract(str_contains($paths['window_manager_js'], 'const saved = this.usesDesktopPersistence()') && str_contains($paths['window_manager_js'], 'const left = saved ? geometry.left'), 'ventanas de escritorio recuperan la posición preferida del usuario');
webOsContract(str_contains($paths['window_manager_js'], 'this.window.innerWidth - 16') && str_contains($paths['window_manager_js'], 'width: Math.round(rect.width)'), 'persistencia conserva el tamaño real de la ventana hasta el máximo visible');
webOsContract(str_contains($paths['window_manager_js'], 'allocatePreferenceSlot(app)') && str_contains($paths['window_manager_js'], "preferenceKey === 'explorer-1'"), 'cada ventana Mis datos usa un slot persistente independiente explorer-1..N');
webOsContract(str_contains($paths['window_manager_js'], 'record.preferenceKey') && str_contains($paths['window_manager_js'], 'savePreference(record.app, geometry, record.preferenceKey)'), 'posición y tamaño se guardan por instancia de Mis datos');
webOsContract(str_contains($paths['window_manager_js'], 'activateTotpResult(result') && str_contains($paths['window_manager_js'], 'navigator.clipboard.writeText(value)') && str_contains($paths['window_manager_js'], 'result._arcadeTotpTimer'), 'TOTP embebido centra interacción de copia y cuenta regresiva');
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
webOsContract(str_contains($paths['desktop_shell_js'], "event.key === 'F4'") && str_contains($paths['desktop_shell_js'], 'requestClose(activeId)'), 'F4 cierra la ventana activa del Web OS mediante cierre protegido');
webOsContract(str_contains($paths['window_manager_js'], 'registerCloseGuard') && str_contains($paths['window_manager_js'], 'async requestClose('), 'WindowManager soporta cierre protegido para aplicaciones con cambios');
webOsContract(str_contains($paths['window_manager_js'], "registerCloseGuard('notebook'") && str_contains($paths['window_manager_js'], 'confirmNotebookClose(record)'), 'Notebook consulta sus cambios antes de cerrarse desde el SO');
webOsContract(str_contains($paths['js'], 'await manager?.requestClose(win)'), 'menú de tareas respeta el cierre protegido');
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
webOsContract(str_contains($paths['node_js'], "card.classList.add('os-node-card-wide')"), 'Identidad local ocupa el mismo ancho y separación visual que los paneles principales');

webOsContract(str_contains($paths['js'], 'createViewerWindow('), 'archivos se abren en ventanas del Web OS');
webOsContract(str_contains($paths['js'], 'openMediaOverlay('), 'audio/video usan reproductor flotante');
webOsContract(str_contains($paths['js'], "mediaFile ? 'Reproducir' : 'Abrir en ventana'"), 'menú de audio/video usa Reproducir en vez de Abrir');
webOsContract(str_contains($paths['js'], "overlay.className = 'os-media-overlay is-' + kind"), 'reproductor multimedia queda superpuesto al SO');
webOsContract(str_contains($paths['js'], 'os-viewer-image'), 'imagen usa visor interno');
webOsContract(str_contains($paths['js'], 'os-viewer-frame'), 'texto/PDF pueden vivir en ventana interna');
webOsContract(str_contains($paths['shell'], 'data-file-action="details"'), 'menú de archivo incluye Detalles');
webOsContract(str_contains($paths['shell'], 'data-created-at='), 'cada archivo expone fecha para Detalles sin consulta adicional');
webOsContract(str_contains($paths['shell'], 'data-context-page-up') && str_contains($paths['shell'], 'data-context-page-down'), 'menú contextual incluye navegación móvil por bloques');
webOsContract(str_contains($paths['js'], 'contextPageSize = 10'), 'móvil pagina exactamente diez acciones por vista');
webOsContract(str_contains($paths['js'], "matchMedia('(max-width: 700px)')"), 'paginación de acciones sólo se activa en pantalla móvil');
webOsContract(str_contains($paths['js'], 'openFileDetails(entry)'), 'Detalles se construye como diálogo interno del Web OS');
webOsContract(str_contains($paths['js'], "['Nombre', name]") && str_contains($paths['js'], "['Tipo', type]") && str_contains($paths['js'], "['Peso', this.formatBytes(bytes)]") && str_contains($paths['js'], "['Fecha de creación', date]"), 'Detalles muestra nombre, tipo, peso y fecha de creación');
webOsContract(str_contains($paths['file_apps_js'], "toolbar.classList.add('os-viewer-toolbar-bottom')"), 'visores pueden mover acciones al pie');
webOsContract(str_contains($paths['file_apps_js'], "application === 'image' || application === 'text'"), 'imagen y editor de texto colocan Descargar en la barra inferior');
webOsContract(str_contains($paths['css'], '.os-statusbar .os-viewer-toolbar-bottom'), 'acciones de documento en footer quedan separadas de los controles de ventana');
webOsContract(
    substr_count($paths['js'], 'this.window.open(') === 2
    && str_contains($paths['js'], "this.window.open(officeUrl, '_blank')")
    && str_contains($paths['js'], "this.window.open(entry.dataset.officeUrl, '_blank')"),
    'sólo Office abre pestañas nuevas desde Mis datos'
);
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
webOsContract(str_contains($paths['shell'], 'data-node-local-label'), 'escritorio incluye etiqueta clicable del nodo local');
webOsContract(str_contains($paths['shell'], '<span>Mis datos</span>'), 'escritorio incluye Mis datos');
webOsContract(str_contains($paths['shell'], '<span>Aplicaciones</span>'), 'escritorio incluye Aplicaciones');
webOsContract(str_contains($paths['shell'], '<span>Notebook</span>') && str_contains($paths['shell'], 'href="notebook.php"'), 'escritorio incluye acceso directo a Notebook');
webOsContract(str_contains($paths['shell'], 'href="notebook.php" target="_blank" rel="noopener"'), 'Notebook abre en pestaña separada y conserva ArcadeCloud OS');
webOsContract(str_contains($paths['shell'], '<strong>Notebook</strong><span>Nueva pestaña</span>'), 'Aplicaciones incluye Notebook en nueva pestaña');
webOsContract(str_contains($paths['shell'], 'nodeKey: <?= json_encode($osPreferenceNodeKey'), 'shell entrega la identidad del nodo al gestor de apariencia');
webOsContract(str_contains($paths['preferences_repository'], "'nodes' => []") && str_contains($paths['preferences_repository'], 'FOR UPDATE'), 'preferencias compartidas en DB se aíslan por nodo sin perder actualizaciones concurrentes');
webOsContract(str_contains($paths['preferences_node_resolver'], 'NodeIdentityService') && str_contains($paths['preferences_node_resolver'], "return 'host:'"), 'nodo de preferencias se resuelve por FederationCloud con fallback local');
$desktopNode = strpos($paths['shell'], 'data-node-local-label');
$desktopData = strpos($paths['shell'], '<span>Mis datos</span>');
$desktopApps = strpos($paths['shell'], '<span>Aplicaciones</span>');
webOsContract(
    $desktopNode !== false && $desktopData !== false && $desktopApps !== false
    && $desktopNode < $desktopData && $desktopData < $desktopApps,
    'accesos del escritorio respetan Nodo local -> Mis datos -> Aplicaciones'
);
webOsContract(str_contains($paths['shell'], 'class="fas fa-gear"'), 'botón inferior izquierdo usa engranaje');
webOsContract(str_contains($paths['shell'], 'os-launcher-profile'), 'perfil del usuario vive dentro del lanzador');
webOsContract(str_contains($paths['shell'], 'data-task-action="minimize"'), 'barra de tareas ofrece minimizar ventana abierta');
webOsContract(str_contains($paths['shell'], 'data-task-action="maximize"'), 'barra de tareas ofrece maximizar ventana minimizada');
webOsContract(str_contains($paths['shell'], 'data-task-action="close"'), 'barra de tareas ofrece cerrar');
webOsContract(str_contains($paths['js'], "minimize.hidden = minimized"), 'tres puntos ocultan minimizar cuando la ventana ya está minimizada');
webOsContract(str_contains($paths['js'], "maximize.hidden = !minimized"), 'tres puntos ofrecen maximizar sólo cuando la ventana está minimizada');
webOsContract(str_contains($paths['js'], 'showTaskContext('), 'barra de tareas abre menú de ventana');
webOsContract(str_contains($paths['window_manager_js'], "viewer:   { width: .42, height: .42"), 'PDF, imagen y texto usan tamaño pequeño centralizado');
webOsContract(str_contains($paths['css'], 'flex:0 0 auto'), 'controles de ventana no se encogen fuera de vista');
webOsContract(str_contains($paths['shell'], 'data-os-reload'), 'engranaje ofrece recargar ArcadeCloud OS');
webOsContract(str_contains($paths['shell'], '> Actualizar</button>'), 'menú inferior usa etiqueta breve Actualizar');
webOsContract(!str_contains($paths['shell'], 'Actualizar ArcadeCloud OS</button>'), 'menú inferior no repite ArcadeCloud OS en Actualizar');
webOsContract(str_contains($paths['js'], 'this.window.location.reload()'), 'Actualizar hace recarga completa');
webOsContract(str_contains($paths['shell'], 'data-os-about'), 'engranaje muestra Acerca de');
webOsContract(!str_contains($paths['shell'], 'Acerca de / Actualizar</button>'), 'Acerca de no se mezcla con la acción Actualizar');
webOsContract(str_contains($paths['shell'], 'id="modalAcercaArcadeCloud"'), 'Web OS incluye diálogo Acerca de');
webOsContract(str_contains($paths['shell'], 'js/arcadecloud-updater.js'), 'Acerca de del Web OS reutiliza actualizador existente');
webOsContract(str_contains($paths['shell'], 'ARCADECLOUD_UPDATER'), 'Web OS entrega configuración del actualizador al superadmin');
webOsContract(str_contains($paths['shell'], 'server_admin_csrf'), 'Web OS prepara CSRF de actualización');
webOsContract(str_contains($paths['updater_js'], 'ARCADECLOUD_UPDATER?.csrf'), 'actualizador acepta configuración segura del Web OS');
webOsContract(str_contains($paths['updater_js'], 'confirmAction(message)') && !str_contains($paths['updater_js'], 'this.window.confirm('), 'actualizador usa diálogo interno y no confirmación nativa del navegador');
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
webOsContract(str_contains($paths['shell'], 'class="os-explorer-live"'), 'Mis datos tiene región reemplazable sin recargar el SO');
webOsContract(str_contains($paths['shell'], "window.UPLOAD_API = 'api/upload.php'"), 'Web OS expone API OOP de subida');
webOsContract(str_contains($paths['shell'], 'class="os-explorer-pathrow"'), 'ruta de carpeta vive arriba de los controles');
webOsContract(!str_contains($paths['shell'], 'data-current-folder-action="sync"'), 'barra de carpeta ya no muestra sincronización con icono de descarga');
webOsContract(!str_contains($paths['shell'], 'fa-cloud-arrow-down'), 'barra de carpeta elimina el icono ambiguo de descarga');
webOsContract(str_contains($paths['shell'], 'data-drive-upload-center'), 'Web OS deja Subir como acción principal de carpeta');
webOsContract(str_contains($paths['shell'], 'data-folder-info'), 'barra compacta deja Información de carpeta');
webOsContract(str_contains($paths['shell'], 'data-selection-actions') && str_contains($paths['shell'], 'data-selection-action="copy"') && str_contains($paths['shell'], 'data-selection-action="cut"'), 'selección múltiple muestra barra contextual con copiar y cortar');
webOsContract(str_contains($paths['shell'], 'data-selection-context="download"'), 'selección múltiple conserva descarga en menú contextual');
webOsContract(str_contains($paths['shell'], 'data-selection-context="delete"'), 'selección múltiple conserva eliminación en menú contextual');
webOsContract(str_contains($paths['js'], 'selectionDivider.hidden = !multi'), 'acciones múltiples sólo aparecen con selección múltiple');
webOsContract(str_contains($paths['clipboard_js'], "moving ? 'Mover aquí' : 'Copiar aquí'"), 'destino distingue Copiar aquí de Mover aquí');
webOsContract(str_contains($paths['shell'], 'Información de carpeta'), 'panel muestra resumen de la carpeta');
webOsContract(str_contains($paths['shell'], 'Visibles (página)'), 'información incluye archivos visibles');
webOsContract(str_contains($paths['shell'], 'Bloqueados (página)'), 'información incluye archivos bloqueados');
webOsContract(str_contains($paths['css'], 'overflow-x:auto'), 'acciones y paginación permanecen en una sola línea desplazable en móvil');
webOsContract(str_contains($paths['shell'], 'js/upload-destination.js'), 'Web OS carga destino inmutable de subida');
webOsContract(str_contains($paths['shell'], 'js/upload-center.js'), 'Web OS carga centro unificado de subida');
webOsContract(!str_contains($paths['shell'], 'js/so-screenshot-paste.js'), 'pegado de screenshot ya no se ejecuta fuera del centro Subir');
webOsContract(str_contains($paths['shell'], "thumb.php?key="), 'imágenes de Mis datos reutilizan ThumbnailService');
webOsContract(str_contains($paths['shell'], 'class="os-entry-thumbnail"'), 'miniaturas se muestran en los iconos de imagen');
webOsContract(str_contains($paths['shell'], 'data-file-action="wallpaper"'), 'cada imagen ofrece usarla como fondo de pantalla');
webOsContract(str_contains($paths['shell'], 'id="osWindowOpacity"'), 'apariencia permite regular la transparencia de ventanas');
webOsContract(str_contains($paths['shell'], 'id="osMenuOpacity"'), 'apariencia permite regular la transparencia de menús');
webOsContract(str_contains($paths['shell'], 'id="settingsWindow"'), 'Configuración abre una ventana del Web OS');
webOsContract(str_contains($paths['shell'], 'data-os-theme="light"') && str_contains($paths['shell'], 'data-os-theme="dark"'), 'apariencia ofrece tema claro y oscuro');
webOsContract(str_contains($paths['shell'], 'data-os-wallpaper-choice="none"'), 'apariencia permite usar el escritorio sin imagen');
webOsContract(str_contains($paths['shell'], 'id="osAppearancePreview"'), 'apariencia incluye vista previa');
webOsContract(str_contains($paths['shell'], 'id="linksWindow"'), 'Enlaces abre una ventana del Web OS');
webOsContract(str_contains($paths['shell'], '<?php if ($canViewPersonalTools): ?>'), 'herramientas AWS se ocultan sin autorización de propietario');
webOsContract(str_contains($paths['shell'], '<?php if ($isSuperAdmin): ?>'), 'consola del servidor se limita visualmente a superadmin');
webOsContract(str_contains($paths['shell'], 'ARCADECLOUD_OS_APPEARANCE'), 'preferencias guardadas se entregan al Web OS');
webOsContract(str_contains($paths['appearance_js'], 'saveRemote()'), 'apariencia persiste cambios en el usuario');
webOsContract(str_contains($paths['preferences_repository'], 'os_preferences'), 'repositorio almacena preferencias JSON');
webOsContract(str_contains($paths['preferences_endpoint'], 'array_replace_recursive($repository->find('), 'guardado conserva claves JSON ajenas y mezcla tamaños por aplicación');
webOsContract(str_contains($paths['preferences_endpoint'], "'windowPreferences'") && str_contains($paths['preferences_endpoint'], "'width'") && str_contains($paths['preferences_endpoint'], "'height'"), 'endpoint persiste tamaños en Users.os_preferences');
webOsContract(str_contains($paths['appearance_js'], 'chromeOpacity: 96') && str_contains($paths['appearance_js'], 'Math.max(0') && str_contains($paths['appearance_js'], "'--os-chrome-opacity'"), 'apariencia permite transparencia real hasta 0% para ventana, menú y chrome');
webOsContract(str_contains($paths['preferences_endpoint'], "'chromeOpacity' => max(0") && str_contains($paths['preferences_endpoint'], "'windowOpacity' => max(0"), 'endpoint persiste opacidades desde cero');
webOsContract(str_contains($paths['shell'], 'id="osChromeOpacity"') && str_contains($paths['shell'], 'min="0" max="100"'), 'Configuración expone opacidad de título/footer y controles desde cero');
webOsContract(str_contains($paths['preferences_endpoint'], "'left'") && str_contains($paths['preferences_endpoint'], "'top'"), 'endpoint persiste posición de ventanas junto con tamaño');
webOsContract(str_contains($paths['preferences_endpoint'], "'wallpaperEnabled'"), 'endpoint persiste el estado sin imagen');
webOsContract(str_contains($paths['preferences_endpoint'], "'theme'"), 'endpoint persiste el tema');
webOsContract(str_contains($paths['preferences_endpoint'], 'HTTP_X_CSRF_TOKEN'), 'endpoint de preferencias conserva CSRF');
webOsContract(str_contains($paths['preferences_schema'], 'information_schema.COLUMNS'), 'migración comprueba el campo antes del ALTER TABLE');
webOsContract(str_contains($paths['updater_service'], 'UserOsPreferencesSchemaService'), 'actualización ejecuta la migración de preferencias');
webOsContract(str_contains($paths['css'], 'height:calc(100dvh - 46px)'), 'escritorio usa la altura dinámica disponible sobre la barra de tareas');
webOsContract(str_contains($paths['css'], 'background-size:cover'), 'fondo cubre el escritorio manteniendo proporción');
webOsContract(str_contains($paths['css'], 'background-image:none!important'), 'modo sin imagen elimina realmente el fondo');
webOsContract(str_contains($paths['shell'], 'js/so-appearance.js'), 'Web OS carga el controlador de apariencia');
webOsContract(str_contains($paths['appearance_js'], 'localStorage.setItem'), 'la apariencia elegida persiste en el navegador');
webOsContract(str_contains($paths['appearance_js'], 'setWallpaper(url, name)'), 'el controlador puede aplicar una imagen seleccionada como fondo');
webOsContract(str_contains($paths['appearance_js'], "classList.toggle('os-theme-light'"), 'tema claro se aplica sin recargar');
webOsContract(str_contains($paths['js'], 'async refreshExplorer('), 'shell actualiza sólo la ventana Mis datos');
webOsContract(str_contains($paths['js'], "current.replaceWith(next)"), 'navegación reemplaza sólo la región de Mis datos');
webOsContract(str_contains($paths['js'], "fetch(url.toString()"), 'navegación de carpetas usa solicitud parcial');
webOsContract(str_contains($paths['js'], "bindHistoryNavigation()"), 'historial atrás/adelante conserva navegación viva');
webOsContract(str_contains($paths['js'], ".os-explorer-window a[data-explorer-route]"), 'sólo enlaces reales pueden navegar o refrescar Mis datos');
webOsContract(!str_contains($paths['js'], "#explorerWindow [data-explorer-route]"), 'clics sobre archivos no ascienden al contenedor de ruta');
webOsContract(str_contains($paths['folders_js'], "ArcadeCloudOsShell.refreshExplorer"), 'acciones de carpeta delegan navegación al shell');
webOsContract(str_contains($paths['folders_js'], 'rebind(root = this.document)'), 'acciones se vuelven a enlazar de forma acotada tras refrescar Mis datos');
webOsContract(str_contains($paths['css'], '.os-entry-thumbnail'), 'miniaturas tienen estilo dentro de Mis datos');
webOsContract(str_contains($paths['css'], '.os-entry.is-selected .os-entry-name') && str_contains($paths['css'], 'color:var(--os-text)!important'), 'nombre de archivo o carpeta sigue legible al seleccionarlo');
webOsContract(str_contains($paths['css'], '.os-media-overlay'), 'audio/video tienen componente flotante');
webOsContract(str_contains($paths['css'], 'z-index:20000'), 'reproductor queda por encima de ventanas y modales');

webOsContract(str_contains($paths['file_list_service'], 'public const WEB_OS_PAGE_SIZE = 30'), 'Mis datos define 30 archivos por página');
webOsContract(str_contains($paths['shell'], "'limite' => FileListService::WEB_OS_PAGE_SIZE"), 'Mis datos usa el tamaño de página compartido');
webOsContract(str_contains($paths['search_controller'], 'FileListService::WEB_OS_PAGE_SIZE'), 'Buscar localiza resultados con el mismo tamaño de página');
webOsContract(str_contains($paths['shell'], 'class="os-folder-pagination"'), 'cada carpeta muestra paginador superior');
webOsContract(str_contains($paths['shell'], '[1, min(3, $pages)]'), 'paginador conserva las tres primeras páginas');
webOsContract(str_contains($paths['shell'], '[max(1, $pages - 2), $pages]'), 'paginador conserva las tres últimas páginas');
webOsContract(str_contains($paths['shell'], '[max(1, $page - 2), min($pages, $page + 2)]'), 'paginador muestra páginas cercanas a la actual');
webOsContract(str_contains($paths['shell'], 'class="os-page-ellipsis"'), 'paginador separa saltos largos con puntos suspensivos');
webOsContract(str_contains($paths['css'], '.os-page-ellipsis'), 'puntos suspensivos tienen estilo compacto');
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
webOsContract(str_contains($paths['security_js'], "'set_file_security.php'"), 'bloqueo reutiliza FileSecurityController');
webOsContract(str_contains($paths['security_js'], "'unlock_file.php'"), 'desbloqueo reutiliza FileSecurityController');
webOsContract(str_contains($paths['security_js'], "'relock_file.php'"), 'rebloqueo reutiliza FileSecurityController');

// Mi nodo en vivo, mantenimiento seguro y compartir completo.
webOsContract(str_contains($paths['shell'], 'js/so-node.js'), 'Web OS carga monitor de nodo en vivo');
webOsContract(str_contains($paths['shell'], 'node-status.php'), 'Mi nodo usa endpoint dedicado de estado');
webOsContract(str_contains($paths['shell'], 'data-node-field="memory_available"'), 'RAM disponible se actualiza en vivo');
webOsContract(str_contains($paths['node_js'], 'data-node-memory-clear'), 'superadmin dispone de escobilla de memoria en Recursos moderno');
webOsContract(str_contains($paths['shell'], 'data-node-field="disk_used"'), 'Mi nodo muestra disco usado en vivo');
webOsContract(str_contains($paths['node_js'], 'data-node-disk-clean'), 'superadmin dispone de escobilla de disco en Recursos moderno');
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

// Seguridad de archivos compartida entre Drive clásico y Web OS.
webOsContract(str_contains($paths['drive'], 'js/file-security.js'), 'Drive clásico carga seguridad compartida');
webOsContract(str_contains($paths['shell'], 'js/file-security.js'), 'Web OS carga seguridad compartida');
webOsContract(str_contains($paths['shell'], 'id="securityFileModal"'), 'Web OS usa el mismo panel completo de seguridad');
webOsContract(str_contains($paths['shell'], 'id="secConfirmInput"'), 'panel de seguridad confirma contraseña');
webOsContract(str_contains($paths['shell'], 'id="secHintInput"'), 'panel de seguridad conserva pista');
webOsContract(str_contains($paths['shell'], 'id="secShowAllCheckbox"'), 'panel permite mostrar u ocultar contraseñas');
webOsContract(str_contains($paths['shell'], 'data-hint='), 'Mis datos conserva la pista del archivo protegido');
webOsContract(str_contains($paths['shell'], 'data-unlocked='), 'Mis datos conserva estado desbloqueado');
webOsContract(str_contains($paths['shell'], 'data-file-action="security-unsecure"'), 'Web OS permite quitar protección igual que el Drive clásico');
webOsContract(str_contains($paths['security_js'], 'class ArcadeCloudFileSecurity'), 'seguridad vive en módulo compartido');
webOsContract(str_contains($paths['security_js'], "askConfirm: true"), 'proteger exige confirmación de contraseña');
webOsContract(str_contains($paths['security_js'], "askHint: true"), 'proteger permite pista');
webOsContract(str_contains($paths['security_js'], "unlock_file.php"), 'desbloqueo reutiliza endpoint existente');
webOsContract(str_contains($paths['security_js'], "relock_file.php"), 'rebloqueo reutiliza endpoint existente');
webOsContract(str_contains($paths['classic_files_js'], 'ArcadeCloudFileSecurity?.protect'), 'bloque de archivos delega protección al módulo compartido');
webOsContract(str_contains($paths['js'], 'ArcadeCloudFileSecurity'), 'Web OS delega seguridad al mismo módulo');
webOsContract(str_contains($paths['js'], 'this.unlockFile(entry);'), 'abrir archivo bloqueado inicia desbloqueo dentro del SO');

// Buscar como aplicación nativa del Web OS.
webOsContract(str_contains($paths['shell'], 'data-window-open="searchWindow"'), 'Aplicaciones incluye Buscar');
webOsContract(str_contains($paths['shell'], 'id="searchWindow"'), 'Buscar funciona como ventana del SO');
webOsContract(str_contains($paths['shell'], 'data-os-search-mode="normal"'), 'Buscar ofrece modo normal');
webOsContract(str_contains($paths['shell'], 'data-os-search-mode="ai"'), 'Buscar ofrece modo IA');
webOsContract(str_contains($paths['shell'], 'js/so-search.js'), 'Web OS carga aplicación Buscar');
webOsContract(str_contains($paths['search_js'], "buscar_archivo.php"), 'Buscar reutiliza endpoint OOP existente');
webOsContract(str_contains($paths['search_js'], "modo: this.mode"), 'app envía normal o IA al mismo controlador');
webOsContract(str_contains($paths['search_js'], "localizar_id"), 'resultado consulta su página real antes de abrir');
webOsContract(str_contains($paths['search_controller'], 'withVisibleRoutes(') && str_contains($paths['search_controller'], "'ruta_visible'"), 'búsqueda normal e IA publican ruta visible de catálogo');
webOsContract(str_contains($paths['search_js'], "desktop.openExplorer(route, { forceNew: true, page })"), 'resultado abre una ventana nueva de Mis datos en la carpeta encontrada');
webOsContract(str_contains($paths['search_js'], "data-file-id") && str_contains($paths['search_js'], "shell.setFileSelected(entry, true)"), 'archivo encontrado queda seleccionado en la nueva ventana');
webOsContract(str_contains($paths['window_manager_js'], 'controller.ready = controller.navigate') && str_contains($paths['window_manager_js'], 'options.page'), 'Explorer permite esperar la página exacta antes de seleccionar resultado');
webOsContract(!str_contains($paths['search_js'], 'shell.refreshExplorer(route') && str_contains($paths['search_js'], 'await explorer.ready'), 'resultado espera su nueva ventana sin reutilizar el Explorer anterior');
webOsContract(str_contains($paths['search_js'], 'is-search-target'), 'archivo encontrado queda resaltado');
webOsContract(str_contains($paths['search_controller'], "postString('localizar_id'"), 'controlador localiza un resultado autenticado');
webOsContract(str_contains($paths['search_service'], 'public function locate('), 'servicio calcula carpeta y página real del archivo');
webOsContract(str_contains($paths['search_service'], 'user_id_ = ?'), 'localización permanece limitada al usuario autenticado');
webOsContract(str_contains($paths['ai_search_service'], "'id' => \$id"), 'resultados IA conservan id de archivo para localizarlo');
webOsContract(str_contains($paths['file_list_service'], 'ORDER BY Fecha DESC, id_ DESC'), 'paginación usa orden determinista para localizar resultados');
webOsContract(!str_contains($paths['search_service'], 'listObjects'), 'búsqueda normal nunca lista S3');
webOsContract(!str_contains($paths['ai_search_service'], 'listObjects'), 'búsqueda IA nunca lista S3');

// Autoapagado del nodo grande por inactividad.
webOsContract(str_contains($paths['drive'], 'js/compute-node-idle.js'), 'Drive clásico vigila inactividad del nodo grande');
webOsContract(str_contains($paths['shell'], 'js/compute-node-idle.js'), 'Web OS vigila inactividad del nodo grande');
webOsContract(str_contains($paths['compute_idle_js'], "this.pollMs = 5000"), 'estado de inactividad se consulta periódicamente');
webOsContract(str_contains($paths['compute_idle_js'], "node_activity: '1'"), 'actividad real reinicia contador del nodo');
webOsContract(str_contains($paths['compute_idle_js'], "node_shutdown_now: '1'"), 'aviso permite solicitar apagado seguro');
webOsContract(str_contains($paths['compute_idle_js'], 'Han pasado 20 minutos sin actividad'), 'aviso explica umbral de veinte minutos');
webOsContract(str_contains($paths['compute_idle_js'], 'data-compute-idle-count'), 'aviso muestra cuenta regresiva');
webOsContract(str_contains($paths['media_node'], 'IDLE_WARNING_SECONDS = 30'), 'backend reserva treinta segundos para advertencia');
webOsContract(str_contains($paths['media_node'], "?: 1200"), 'backend usa veinte minutos de inactividad por defecto');
webOsContract(str_contains($paths['media_node'], 'touchInteractiveActivity'), 'actividad de s3/so se registra en la sesión EC2');
webOsContract(str_contains($paths['media_node'], 'requestIdleStop'), 'apagado interactivo pasa por servicio del nodo');
webOsContract(str_contains($paths['media_node'], "(\$idle['warning'] ?? false) !== true"), 'StopInstances interactivo sólo se permite durante aviso de inactividad');
webOsContract(str_contains($paths['media_controller'], "queryString('idle_status')"), 'endpoint expone estado de inactividad');
webOsContract(str_contains($paths['media_controller'], "postString('node_activity')"), 'endpoint acepta heartbeat autenticado');
webOsContract(str_contains($paths['media_controller'], "postString('node_shutdown_now')"), 'endpoint acepta apagado desde advertencia');
webOsContract(str_contains($paths['compute_idle_css'], '.compute-idle-warning'), 'aviso de apagado tiene UI compartida');

// Apagado manual forzado desde el perfil del Web OS.
webOsContract(str_contains($paths['shell'], 'data-fastdrive-force-stop'), 'perfil superadmin muestra botón de apagado FastDrive');
webOsContract(str_contains($paths['shell'], 'id="fastDrivePowerModal"'), 'apagado solicita contraseña en diálogo propio');
webOsContract(str_contains($paths['shell'], 'js/so-power.js'), 'Web OS carga controlador de apagado');
webOsContract(str_contains($paths['power_js'], "action: 'force-stop'"), 'controlador solicita únicamente apagado forzado');
webOsContract(str_contains($paths['power_js'], 'current_password'), 'apagado envía contraseña actual para reautenticación');
webOsContract(str_contains($paths['power_endpoint'], 'isSuperAdmin()'), 'endpoint de apagado exige superadmin');
webOsContract(str_contains($paths['power_endpoint'], 'fastdrive_control_csrf'), 'endpoint de apagado exige CSRF dedicado');
webOsContract(str_contains($paths['power_endpoint'], '->forceStop('), 'endpoint delega el apagado al servicio OOP');

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
webOsContract(str_contains($paths['shell'], 'id="federationApp"') && !str_contains($paths['shell'], 'id="federationFrame"'), 'FederationCloud se ejecuta nativamente dentro de la ventana');
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
webOsContract(!str_contains($launcherMarkup, 'id="osWindowOpacity"') && !str_contains($launcherMarkup, 'id="osMenuOpacity"'), 'lanzador ya no contiene sliders de apariencia');
webOsContract(str_contains($launcherMarkup, 'data-window-open="settingsWindow"'), 'lanzador abre Configuración');
webOsContract(str_contains($launcherMarkup, 'data-window-open="linksWindow"'), 'lanzador abre Enlaces');
webOsContract(!str_contains($launcherMarkup, 'terminalWindow'), 'Terminal ya no aparece en el menú inferior izquierdo');
webOsContract(str_contains($paths['shell'], '<strong>Consola servidor</strong>'), 'Consola servidor aparece dentro de Aplicaciones');
webOsContract(str_contains($paths['shell'], '<strong>Linux XFCE</strong>'), 'Aplicaciones incluye acceso explícito al escritorio Linux');
webOsContract(str_contains($paths['shell'], 'href="office-launch.php" target="_blank" rel="noopener" title="Abrir escritorio Linux remoto por noVNC"'), 'Linux XFCE reutiliza el gateway autenticado noVNC');
webOsContract(str_contains($paths['shell'], '<strong>Guacamole</strong>'), 'Aplicaciones incluye acceso explícito a Guacamole');
webOsContract(str_contains($paths['shell'], 'href="office-launch.php?target=guacamole"'), 'Guacamole usa el launcher autenticado de Office');

webOsContract(str_contains($paths['federation_portal_controller'], "queryString('embed')"), 'portal soporta modo embebido desde Controller');
webOsContract(str_contains($paths['federation_portal_renderer'], 'federation-portal-embedded'), 'portal embebido elimina chrome duplicado');
webOsContract(str_contains($paths['federation_admin_controller'], 'federation_provider_csrf'), 'Acerca de prepara CSRF de solicitudes de proveedores');
webOsContract(str_contains($paths['federation_admin_controller'], 'server_admin_csrf'), 'Acerca de prepara CSRF de configuración del servidor');
webOsContract(str_contains($paths['federation_admin_renderer'], 'federationAboutNode'), 'Acerca de muestra datos del nodo');
webOsContract(str_contains($paths['federation_admin_renderer'], 'federationAboutPeerList'), 'Acerca de muestra nodos conectados');
webOsContract(str_contains($paths['federation_admin_renderer'], 'federationProviderPendingList'), 'Acerca de muestra solicitudes de proveedor');
webOsContract(str_contains($paths['federation_admin_renderer'], 'modalServerAdmin'), 'Acerca de integra configuración del servidor');
webOsContract(str_contains($paths['federation_admin_renderer'], 'federationAboutModeration'), 'Acerca de integra moderación');
webOsContract(str_contains($paths['federation_admin_js'], "/federationcloud/nodes.php"), 'Acerca de usa ruta estable del directorio FederationCloud');
webOsContract(str_contains($paths['federation_admin_js'], "/federationcloud/provider-admin.php"), 'solicitudes usan endpoint absoluto de proveedores');
webOsContract(str_contains($paths['federation_admin_js'], "/federationcloud/moderation-api.php"), 'Acerca de consulta moderación con ruta absoluta');
webOsContract(str_contains($paths['server_admin_js'], 'dataset.endpoint'), 'configuración del servidor acepta endpoint reutilizable');
webOsContract(str_contains($paths['moderation_renderer'], 'federation-moderation-embedded'), 'moderación soporta modo embebido');

webOsContract(
    str_contains($paths['federation_admin_renderer'], 'os-node-dashboard')
    && str_contains($paths['federation_admin_renderer'], 'os-node-card os-node-card-wide')
    && str_contains($paths['federation_admin_renderer'], '<dt>Nombre</dt><dd id="federationAboutNodeName">')
    && str_contains($paths['federation_admin_renderer'], '<dt>Federation URL</dt>'),
    'Acerca de FederationCloud usa la estructura exacta de tarjetas y filas de Mi nodo'
);
webOsContract(
    str_contains($paths['ec2_page'], 'class="os-node-card ec2-instance-card"')
    && str_contains($paths['ec2_page'], '<dt>Instance ID</dt>')
    && str_contains($paths['ec2_page'], '<dt>IPv4 privada</dt>')
    && str_contains($paths['ec2_page'], 'id="rdsTbl" class="os-node-dashboard'),
    'Gestión EC2 reemplaza tablas por tarjetas y filas del patrón Mi nodo'
);
webOsContract(
    str_contains($paths['system_panel_css'], '.os-system-shell .os-node-card dl')
    && str_contains($paths['system_panel_css'], '.os-system-shell .os-node-card dt')
    && str_contains($paths['system_panel_css'], '.os-system-shell .os-node-card dd')
    && str_contains($paths['system_panel_css'], '.os-system-shell .os-node-card-wide'),
    'piel compartida replica las primitivas exactas de Mi nodo'
);
webOsContract(substr_count($paths['moderation_renderer'], "/federationcloud/moderation-api.php") >= 3, 'moderación embebida usa endpoint absoluto para leer, decidir y desbloquear');

webOsContract(str_contains($paths['shell'], 'id="osNodeHealthButton"') && str_contains($paths['shell'], 'id="osNodeHealthPopover"'), 'barra de tareas muestra foco de estado del nodo local');
webOsContract(str_contains($paths['shell'], 'id="osResourceHistoryButton"') && str_contains($paths['shell'], 'data-node-spark="network"'), 'barra de tareas integra mini gráficas Linux de CPU, RAM, red y disco');
webOsContract(str_contains($paths['node_js'], 'this.resourceHistory = { cpu: [], memory: [], network: [], disk: [] }') && str_contains($paths['node_js'], 'drawResourceHistory()'), 'monitor mantiene historial visual de recursos');
webOsContract(str_contains($paths['node_controller'], "'network' => (array)(\$resources['network'] ?? [])"), 'estado público conserva contadores agregados seguros de red');
webOsContract(str_contains($paths['node_js'], 'renderTaskbarStatus(this.node)') && str_contains($paths['node_js'], 'NODO LOCAL · '), 'estado del nodo alimenta el foco y su tarjeta resumida');
webOsContract(str_contains($paths['shell'], 'id="osReplicaHealthButton"') && str_contains($paths['node_js'], 'federationcloud/nodes.php'), 'superadmin recibe foco de nodo federado activo');
webOsContract(str_contains($paths['shell'], 'id="osTaskCenterButton"') && str_contains($paths['shell'], 'os-task-center-count'), 'Centro de Tareas está integrado como icono con contador en la barra del OS');
webOsContract(str_contains($paths['shell'], 'id="pageMonitorWindow"') && str_contains($paths['shell'], 'data-page-monitor'), 'Administrador de la página existe como ventana nativa');
webOsContract(str_contains($paths['shell'], 'data-page-memory-chart') && str_contains($paths['shell'], 'data-page-window-list'), 'Administrador incluye gráfica y lista de ventanas');
webOsContract(str_contains($paths['page_task_manager_js'], 'estimateWindowBytes(record)') && str_contains($paths['page_task_manager_js'], 'data-page-window-close'), 'Administrador estima huella por ventana y permite cerrarla');
webOsContract(str_contains($paths['page_task_manager_js'], 'performance?.memory'), 'Administrador conserva memoria JS real global cuando Chromium la expone');

echo "WEB_OS_CONTRACT_OK\n";
