<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$files = [
    'shell' => $root . '/js/so.js',
    'clipboard' => $root . '/js/so-clipboard.js',
    'uploadCenter' => $root . '/js/upload-center.js',
    'chunkedUploader' => $root . '/upload/drivers/Chunked15MBUploader.php',
    'uploadDestination' => $root . '/js/upload-destination.js',
    'backgroundTasks' => $root . '/js/background-tasks.js',
    'uploadCenterCss' => $root . '/css/upload-center.css',
    'classicFiles' => $root . '/bloque_archivos.php',
    'classicShell' => $root . '/s3.php',
    'webOsShell' => $root . '/so.php',
    'clipboardCss' => $root . '/css/so-clipboard.css',
    'moveTasks' => $root . '/js/move-tasks.js',
    'runtime' => $root . '/js/os-window-manager.js',
    'fileService' => $root . '/src/Application/FileMutationService.php',
    'fileRepo' => $root . '/src/Storage/FileRecordRepository.php',
    'folderService' => $root . '/src/Application/FolderMutationService.php',
    'folderRepo' => $root . '/src/Storage/FolderMutationRepository.php',
    'moveService' => $root . '/src/Application/MoveJobService.php',
    'moveController' => $root . '/src/Http/Controller/MoveJobController.php',
    'share' => $root . '/js/so-share.js',
    'arcadelinkShare' => $root . '/js/arcadelink-share.js',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Falta archivo de contrato: {$name} ({$path})\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
};

$assert(str_contains($files['clipboard'], 'longPressMs = 560'), 'pulsación larga abre acciones');
$assert(str_contains($files['clipboard'], "entry.addEventListener('dragstart', clear)"), 'arrastrar cancela pulsación larga');
$assert(str_contains($files['shell'], 'fileSecondClickMs = 320'), 'segundo clic en 320 ms abre el archivo');
$assert(str_contains($files['shell'], 'toggleFileSelection(entry)'), 'clic simple alterna selección de archivo');
$assert(str_contains($files['shell'], 'selectedFileEntries()'), 'shell expone selección múltiple');
$assert(str_contains($files['shell'], "classList.toggle('is-selected'"), 'selección mantiene estado visual');
$assert(str_contains($files['clipboard'], 'captureFiles(entry, mode, context = {})'), 'portapapeles captura uno o varios archivos con contexto de ventana');
$assert(str_contains($files['clipboard'], 'keys.length + \' archivos\''), 'portapapeles etiqueta lotes múltiples');
$assert(str_contains($files['clipboard'], 'JSON.stringify(keys)'), 'pegar envía todos los archivos seleccionados');
$assert(str_contains($files['clipboard'], "data-os-clipboard-action"), 'menús incorporan acciones de portapapeles');
$assert(str_contains($files['clipboard'], "operation: item.mode"), 'pegar conserva copy o move');
$assert(str_contains($files['clipboard'], 'sourceWindowId: context.sourceWindowId') && str_contains($files['clipboard'], 'sourceRoute: context.sourceRoute') && str_contains($files['clipboard'], 'items: keys'), 'portapapeles global conserva origen, elementos y operación');
$assert(str_contains($files['clipboard'], 'ruta_actual: String(item.sourceRoute || this.currentRoute())'), 'pegado conserva la ruta de origen aunque la misma Explorer navegue');
$assert(str_contains($files['clipboard'], 'destinationWindowId: String(context.destinationWindowId || \'\')'), 'pegado identifica la Explorer destino sin exigir otra ventana');
$assert(str_contains($files['runtime'], 'chooseDropOperation(data.keys.length') && str_contains($files['runtime'], "clipboard.paste?.(destinationRoute, { destinationWindowId: this.id })"), 'drag/drop entre Explorers ejecuta la misma transferencia copy/move hacia la ventana destino');
$assert(str_contains($files['shell'], "querySelectorAll('.os-explorer-window').forEach") && str_contains($files['shell'], "owner.querySelectorAll('.os-file-entry.is-selected')"), 'acciones de selección se mantienen independientes por cada ventana Explorer');
$assert(str_contains($files['clipboard'], 'emitFilesystemChanged?.({') && str_contains($files['runtime'], "this.bus.on('filesystem:changed'") && str_contains($files['runtime'], 'refreshAffected(detail = {})'), 'movimiento publica rutas y refresca todas las Explorer afectadas mediante el EventBus');
$assert(str_contains($files['moveTasks'], 'ArcadeCloudOsClipboard?.activeTransfer'), 'tarea gestionada por el OS omite refresco global legacy');
$assert(str_contains($files['clipboard'], 'injectPasteToolbar()'), 'SO crea botón temporal para pegar en la carpeta actual');
$assert(!str_contains($files['clipboard'], 'visibleFiles >= 20'), 'botón superior ya no depende de una página llena');
$assert(str_contains($files['clipboard'], 'button.hidden = !hasClipboard'), 'botón superior permanece visible mientras exista portapapeles');
$assert(str_contains($files['clipboard'], "moving ? 'Mover aquí' : 'Copiar aquí'"), 'botón superior identifica claramente la operación pendiente');
$assert(str_contains($files['clipboard'], "dataset.osPasteCurrent"), 'botón superior se identifica como destino actual');
$assert(str_contains($files['clipboardCss'], '.os-toolbar-paste'), 'botón superior tiene estilo propio');
$assert(str_contains($files['webOsShell'], 'js/so-clipboard.js?v=<?= (int)filemtime') && !str_contains($files['moveTasks'], 'loadWebOsClipboard'), 'Web OS carga el clipboard de forma determinista aunque todavía no exista una Explorer');
$assert(str_contains($files['clipboard'], 'static boot(win = window, doc = document)') && !str_contains($files['clipboard'], "querySelector('.os-explorer-live')) return"), 'clipboard puede iniciar antes de crear ventanas Explorer dinámicas');
$assert(str_contains($files['clipboard'], 'ArcadeCloudOsShare?.open'), 'menú del SO abre panel completo de compartir');
$assert(!str_contains($files['clipboard'], "¿Cuántos días debe funcionar"), 'compartir ya no usa prompt del navegador');
$assert(str_contains($files['share'], "generar_token.php"), 'panel completo genera enlace directo');
$assert(str_contains($files['arcadelinkShare'], 'federationcloud/create.php'), 'panel completo conserva ArcadeLink FederationCloud');
$assert(str_contains($files['clipboard'], "eliminar_archivo.php"), 'menú del SO puede eliminar archivos');
$assert(str_contains($files['clipboard'], "osTransferHud"), 'SO muestra medidor de transferencia');
$assert(str_contains($files['moveTasks'], "drive:move-task-progress"), 'polling publica progreso al SO');
$assert(str_contains($files['fileService'], 'public function copy('), 'servicio de archivos soporta copia');
$assert(str_contains($files['fileRepo'], 'duplicateFrom'), 'copia crea registro nuevo en FileS3');
$assert(str_contains($files['folderService'], 'public function copy('), 'servicio de carpetas soporta copia');
$assert(str_contains($files['folderRepo'], 'copyTree'), 'copia duplica catálogo de carpetas y archivos');
$assert(str_contains($files['moveService'], "['move', 'copy']"), 'job en segundo plano acepta copy y move');
$assert(str_contains($files['moveController'], "'progress' => \$progress"), 'estado del job expone porcentaje');
$assert(str_contains($files['classicFiles'], 'data-drive-upload-center'), 'Drive clásico ofrece Subir dentro del bloque de archivos');
$assert(str_contains($files['webOsShell'], 'data-drive-upload-center'), 'Web OS ofrece Subir dentro de Mis datos');
$assert(str_contains($files['classicShell'], 'js/upload-center.js'), 'Drive clásico carga el mismo centro de subida');
$assert(str_contains($files['webOsShell'], 'js/upload-center.js'), 'Web OS carga el mismo centro de subida');
$assert(str_contains($files['uploadCenter'], "navigator.clipboard.read"), 'portapapeles se consulta desde el centro de subida');
$assert(str_contains($files['uploadCenter'], 'async open(context = null)'), 'revisión de portapapeles nace al abrir Subir con contexto de Explorer');
$assert(str_contains($files['uploadCenter'], 'this.open(this.contextForButton(button))'), 'el botón Subir entrega su Explorer propietario');
$assert(str_contains($files['uploadDestination'], "activeExplorerRoute = document.querySelector('.os-explorer-window.is-active .os-explorer-live')") && str_contains($files['uploadDestination'], 'contextFromElement(element)'), 'destino de subida prioriza la Explorer propietaria/activa y no una ruta global obsoleta');
$assert(str_contains($files['uploadCenter'], 'await this.inspectClipboard()'), 'Subir revisa imagen/texto sólo bajo acción del usuario');
$assert(str_contains($files['uploadCenter'], "data-upload-paste-image"), 'centro ofrece pegar imagen cuando existe');
$assert(str_contains($files['uploadCenter'], "data-upload-paste-text"), 'centro ofrece pegar texto cuando existe');
$assert(str_contains($files['uploadCenter'], "this.timestampName('screenshot', 'png')"), 'imagen del portapapeles se guarda como screenshot PNG con fecha/hora');
$assert(str_contains($files['uploadCenter'], "this.timestampName('clipboard', 'txt')"), 'texto del portapapeles se guarda como TXT con fecha/hora');
$assert(str_contains($files['uploadCenter'], 'data-upload-dropzone'), 'centro ofrece Dropzone/múltiples archivos');
$assert(str_contains($files['uploadCenter'], "mode=remote_url&action=init"), 'centro conserva subida desde enlace');
$assert(str_contains($files['uploadCenter'], "mode=chunked&action=init"), 'centro conserva multipart para archivos grandes');
$assert(str_contains($files['uploadCenter'], "filesize: String(file.size)"), 'complete multipart conserva tamaño real para registro/costos');
$assert(str_contains($files['uploadCenter'], "Math.min(128, mb)") && str_contains($files['uploadCenter'], "let mb = 16"), 'multipart limita partes del navegador para reintentos confiables');
$assert(str_contains($files['uploadCenter'], "center.mergeClientTasks()"), 'subidas sincronizan explícitamente el Centro de Tareas en cualquier orden de carga');
$assert(str_contains($files['uploadCenter'], "Revisa conectividad y CORS"), 'errores de PUT distinguen conectividad/CORS');
$assert(!str_contains($files['chunkedUploader'], "'ContentLength' => \$contentLength"), 'URL prefirmada multipart no firma Content-Length controlado por el navegador');
$assert(str_contains($files['uploadCenter'], "mode=local_put&action=init"), 'centro conserva subida directa local_put');
$assert(str_contains($files['uploadCenter'], "mode=local_put&action=complete"), 'subida directa confirma FileS3');
$assert(str_contains($files['uploadCenter'], "'X-Drive-CSRF': this.csrf"), 'centro de subida conserva CSRF');
$assert(str_contains($files['uploadCenter'], "destination: String(route || '')"), 'cada tarea fija su carpeta destino al crearse');
$assert(str_contains($files['uploadCenter'], "this.localQueue"), 'subidas múltiples continúan en una cola independiente del modal');
$assert(str_contains($files['uploadCenter'], "taskSnapshots()"), 'administrador expone subidas al Centro de Tareas');
$assert(str_contains($files['backgroundTasks'], "'drive:client-upload-task'"), 'Centro de Tareas recibe progreso de subidas del navegador');
$assert(str_contains($files['backgroundTasks'], "task.kind === 'upload'"), 'Centro de Tareas renderiza destino y progreso de subida');
$assert(str_contains($files['uploadDestination'], "ArcadeCloudOsShell.refreshExplorer"), 'destino compartido refresca Web OS sin cambiar de carpeta');
$assert(str_contains($files['uploadDestination'], "actualizarBloqueArchivos"), 'destino compartido refresca Drive clásico sin recargar página');
$assert(str_contains($files['uploadCenterCss'], '.drive-upload-center'), 'centro de subida tiene UI compartida');
$assert(str_contains($files['uploadCenter'], 'dismissTask(id)'), 'tareas de subida terminadas admiten limpieza segura');
$assert(str_contains($files['uploadCenter'], "id: 'dismiss'"), 'subidas terminales conservan eliminación individual');
$assert(str_contains($files['backgroundTasks'], 'data-bg-task-select'), 'Centro de Tareas permite seleccionar terminales');
$assert(str_contains($files['backgroundTasks'], 'removeSelectedTasks()'), 'Centro de Tareas elimina un grupo seleccionado');
$assert(str_contains($files['backgroundTasks'], 'cleanupTerminalTasks()'), 'Centro de Tareas limpia finalizadas en bloque');
$assert(str_contains($files['backgroundTasks'], 'Promise.allSettled'), 'limpieza masiva procesa lotes sin abortar por un fallo individual');


fwrite(STDOUT, "Contrato Web OS clipboard correcto.\n");
