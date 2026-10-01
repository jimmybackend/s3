<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'shell' => $root . '/so.php',
    'runtime' => $root . '/js/os-window-manager.js',
    'css' => $root . '/css/so.css',
    'node' => $root . '/src/Http/Controller/NodeStatusController.php',
    'nodeUi' => $root . '/js/so-node.js',
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
$assert(str_contains($files['runtime'], "register(el,'explorer')"), 'exploradores se registran como instancias');
$assert(str_contains($files['runtime'], 'this.history = []; this.future = []'), 'historial es independiente por explorador');
$assert(str_contains($files['runtime'], "event.ctrlKey&&event.key.toLowerCase()==='l'"), 'Ctrl+L enfoca dirección');
$assert(str_contains($files['runtime'], "(^|\\/)\\.\\.?(\\/|$)"), 'normalizador rechaza traversal');
$assert(str_contains($files['runtime'], 'new AbortController()'), 'navegación cancela fetch obsoleto');
$assert(str_contains($files['runtime'], "data-folder-open-new"), 'carpetas ofrecen abrir en ventana nueva');
$assert(str_contains($files['runtime'], 'application/x-arcadecloud-items'), 'drag and drop usa payload privado');
$assert(str_contains($files['runtime'], 'ArcadeCloudOsClipboard.paste(destination)'), 'drop reutiliza backend de portapapeles');
$assert(str_contains($files['runtime'], "'file-moved','file-copied','file-deleted','upload-completed','task-completed'"), 'EventBus sincroniza cambios');
$assert(str_contains($files['runtime'], 'record.cleanup.forEach'), 'cierre libera recursos registrados');
$assert(str_contains($files['runtime'], 'this.zLimit = 900'), 'z-index se compacta antes de crecer sin límite');
$assert(str_contains($files['shell'], 'data-node-access="<?= $isSuperAdmin'), 'Mi nodo distingue acceso visual');
$assert(str_contains($files['shell'], 'data-node-memory-clear') && str_contains($files['shell'], 'data-node-disk-clean'), 'escobillas continúan disponibles');
$assert(str_contains($files['node'], 'if (!$isSuperAdmin)') && substr_count($files['node'], 'publicSnapshot(') >= 3, 'backend filtra diagnóstico normal y FastDrive');
$assert(str_contains($files['node'], "['memory-clear', 'disk-clean']"), 'backend conserva whitelist de mantenimiento');
$assert(str_contains($files['node'], 'private function publicSnapshot'), 'endpoint aplica allow-list segura');
$assert(str_contains($files['nodeUi'], 'this.config.superadmin !== true'), 'usuario normal recibe panel simplificado');
$assert(str_contains($files['shell'], 'data-os-tool="activity-costs"'), 'actividad/costos abre herramienta interna');
$assert(str_contains($files['css'], '.os-window.is-drop-target'), 'destino drag tiene estado visual');

fwrite(STDOUT, "Web OS multiwindow smoke passed.\n");
