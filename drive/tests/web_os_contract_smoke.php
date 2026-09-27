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
webOsContract(str_contains($paths['js'], 'os-viewer-video'), 'video usa visor interno');
webOsContract(str_contains($paths['js'], 'os-viewer-audio'), 'audio usa visor interno');
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

echo "WEB_OS_CONTRACT_OK\n";
