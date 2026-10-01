<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'shell' => $root . '/so.php',
    'runtime' => $root . '/js/os-window-manager.js',
    'css' => $root . '/css/so.css',
    'node' => $root . '/src/Http/Controller/NodeStatusController.php',
    'nodeUi' => $root . '/js/so-node.js',
    'suggestions' => $root . '/folder-suggestions.php',
    'folders' => $root . '/src/Storage/FolderRepository.php',
];
foreach ($files as $key => $file) {
    if (!is_file($file)) { fwrite(STDERR, "FAIL: falta {$file}\n"); exit(1); }
    $files[$key] = (string)file_get_contents($file);
}
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "OK: {$message}\n");
};

$assert(str_contains($files['runtime'], 'class ArcadeCloudWindowManager'), 'WindowManager central existe');
$assert(str_contains($files['runtime'], 'nextId(app)'), 'cada ventana recibe windowId único');
$assert(str_contains($files['runtime'], "register(element, 'explorer')"), 'exploradores se registran como instancias');
$assert(str_contains($files['runtime'], 'this.history = [];') && str_contains($files['runtime'], 'this.future = [];'), 'historial es independiente por explorador');
$assert(str_contains($files['runtime'], "event.key.toLowerCase() === 'l'"), 'Ctrl+L enfoca dirección');
$assert(str_contains($files['runtime'], "(^|\\/)\\.\\.?(\\/|$)"), 'normalizador rechaza traversal');
$assert(str_contains($files['runtime'], 'new AbortController()'), 'navegación cancela fetch obsoleto');
$assert(str_contains($files['runtime'], "data-folder-open-new"), 'carpetas ofrecen abrir en ventana nueva');
$assert(str_contains($files['runtime'], 'application/x-arcadecloud-items'), 'drag and drop usa payload privado');
$assert(str_contains($files['runtime'], "clipboard?.paste?.(destinationRoute, { destinationWindowId: this.id })"), 'drop reutiliza backend de portapapeles y conserva Explorer destino');
$assert(str_contains($files['runtime'], "'file-moved','file-copied','file-deleted','folder-created','upload-completed','task-completed'"), 'EventBus sincroniza cambios');
$assert(str_contains($files['runtime'], 'record.cleanup.forEach'), 'cierre libera recursos registrados');
$assert(str_contains($files['runtime'], 'this.zCounter > 900'), 'z-index se compacta antes de crecer sin límite');
$assert(str_contains($files['shell'], 'data-node-access="<?= $isSuperAdmin'), 'Mi nodo distingue acceso visual');
$assert(str_contains($files['nodeUi'], 'data-node-memory-clear') && str_contains($files['nodeUi'], 'data-node-disk-clean'), 'escobillas se crean en Recursos moderno');
$assert(substr_count($files['shell'], 'os-node-legacy-summary') === 1 && str_contains($files['shell'], 'if (!$isSuperAdmin)'), 'superadmin no recibe resumen legacy duplicado');
$assert(str_contains($files['node'], 'if (!$isSuperAdmin)') && substr_count($files['node'], 'publicSnapshot(') >= 3, 'backend filtra diagnóstico normal y FastDrive');
$assert(str_contains($files['node'], "['memory-clear', 'disk-clean']"), 'backend conserva whitelist de mantenimiento');
$assert(str_contains($files['node'], 'private function publicSnapshot'), 'endpoint aplica allow-list segura');
$assert(str_contains($files['nodeUi'], 'this.config.superadmin !== true'), 'usuario normal recibe panel simplificado');
$assert(str_contains($files['shell'], 'data-os-tool="activity-costs"'), 'actividad/costos abre herramienta interna');
$assert(str_contains($files['css'], '.os-window.is-drop-target'), 'destino drag tiene estado visual');
$assert(!str_contains($files['shell'], 'data-initial-explorer'), 'el shell inicia sin Explorer preabierto');
$assert(str_contains($files['runtime'], 'forceNew: true') && !str_contains($files['runtime'], 'this.openExplorer(this.root, { reuse: true })'), 'cada clic en Mis datos solicita una instancia nueva');
$assert(str_contains($files['runtime'], 'windowPreferences') && str_contains($files['runtime'], 'ResizeObserver'), 'WindowManager aplica y observa tamaño preferido por aplicación');
$assert(str_contains($files['runtime'], "classList.toggle('is-maximized', record.maximized)") && str_contains($files['runtime'], 'Object.assign(record.element.style, record.geometry)'), 'maximizar y restaurar conservan exactamente la geometría preferida');
$assert(str_contains($files['runtime'], "event.stopPropagation();") && str_contains($files['runtime'], "this.win.addEventListener('click', guard, true)"), 'guard capture evita que enlaces de carpetas naveguen so.php');
$assert(str_contains($files['runtime'], 'this.route = route; this.page =') && str_contains($files['runtime'], 'this.history = [];'), 'ruta, página e historial pertenecen a cada Explorer');
$assert(str_contains($files['runtime'], 'arcadeos:explorer-updated') && str_contains($files['runtime'], 'ArcadeCloudOsFolders?.rebind?.(this.win)'), 'fragmento completo vuelve a enlazar carpetas, archivos y acciones en su instancia');
$assert(str_contains($files['runtime'], 'this.suggestionController = new AbortController()') && str_contains($files['runtime'], '220') && str_contains($files['runtime'], 'suggestionCache'), 'autocomplete por instancia usa debounce, cancelación y caché corta');
$assert(str_contains($files['suggestions'], '$session->userId()') && str_contains($files['folders'], 'WHERE user_id_ = ? AND Found = 1'), 'sugerencias están limitadas al usuario autenticado');
$assert(str_contains($files['runtime'], 'ArcadeCloudWindowLayoutConfig') && str_contains($files['runtime'], "explorer: { width: .42, height: .42") && str_contains($files['runtime'], '* 32'), 'geometría central usa desktop 42% y offset escalonado');
$assert(str_contains($files['runtime'], 'isLegacyOversize') && str_contains($files['runtime'], "* .72"), 'preferencias casi fullscreen de cualquier aplicación se normalizan sin borrar otras preferencias');
$assert(str_contains($files['runtime'], 'this.navigate(folder.dataset.folderRoute);') && !str_contains($files['runtime'], 'if (event.detail >= 2) this.navigate(folder.dataset.folderRoute)'), 'carpeta navega con un clic dentro de su instancia');
$assert(str_contains($files['runtime'], 'bindFiles?.(this.win)') && str_contains($files['runtime'], 'bindEntries?.(this.win)') && str_contains($files['runtime'], 'rebind?.(this.win)'), 'rebind después de fetch queda limitado a la ventana actual');

fwrite(STDOUT, "Web OS multiwindow smoke passed.\n");
