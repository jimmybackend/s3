<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$shell = $read('so.php');
$node = $read('js/so-node.js');
$windows = $read('js/os-window-manager.js');
$controller = $read('src/Http/Controller/NodeStatusController.php');
$probe = $read('src/Admin/ServerTaskActivityProbe.php');
$helper = $read('bin/arcadecloud-drive-admin-helper.php');
$ok = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "OK: {$message}\n");
};

$ok(str_contains($node, 'this.config.superadmin === true') && str_contains($node, 'data-node-memory-clear'), 'escobilla RAM moderna se genera sólo dentro de guard superadmin');
$ok(str_contains($node, 'data-node-disk-clean'), 'escobilla de disco moderna está disponible para superadmin');
$ok(str_contains($controller, 'if (!$session->isSuperAdmin())') && str_contains($controller, "], 403)"), 'backend devuelve 403 antes del mantenimiento para no-superadmin');
$ok(str_contains($controller, "['memory-clear', 'disk-clean']") && str_contains($controller, 'HTTP_X_SERVER_ADMIN_CSRF'), 'acciones usan whitelist y CSRF');
$ok(str_contains($node, 'updateMemoryFields(data.node || {})') && str_contains($node, 'updateDiskFields(data.node || {})'), 'mantenimiento refresca sólo RAM o disco');
$ok(str_contains($probe, "'office' => \$this->hasActiveOfficeSessions()") && substr_count($probe, 'hasActiveJobs()') >= 3, 'limpieza espera Office, sync, move y media activos');
$ok(str_contains($helper, 'Temporales:') && str_contains($helper, 'Logs:') && str_contains($helper, 'Otros seguros:'), 'resultado de disco informa bytes por categoría sin rutas');

foreach (['activity-costs', 'aws', 'ec2', 's3-sync'] as $tool) {
    $ok(str_contains($shell, 'data-os-tool="' . $tool . '"'), "{$tool} abre como herramienta interna");
}
$ok(str_contains($shell, 'data-window-open="terminalWindow"') && str_contains($shell, 'data-window-open="federationWindow"'), 'Consola y Federation reutilizan ventanas internas existentes');
$ok(!str_contains($shell, 'href="aws.php" target="_blank"') && !str_contains($shell, 'href="ec2.php" target="_blank"'), 'herramientas propias no fuerzan navegación externa');
$ok(str_contains($windows, "this.manager.register(element, app") && str_contains($windows, "const app = 'tool-'"), 'WindowManager registra una tarea independiente por herramienta');
$ok(str_contains($windows, "page.querySelector('main')") && !str_contains($windows, '<iframe class="os-viewer-frame" title="Herramienta"'), 'cargador importa sólo main, sin iframe ni navbar global');

$ok(str_contains($node, 'updateIdleCountdown()') && str_contains($node, 'this.idleRemaining - 1'), 'countdown local actualiza cada segundo');
$ok(str_contains($node, 'setInterval(() => { if (this.isOpen()) this.refresh()') && str_contains($node, '}, 30000)'), 'polling real permanece en 30 segundos');
$ok(str_contains($node, "else if (this.selected === 'fastdrive') this.updateFastDriveFields"), 'polling FastDrive usa actualización incremental');
$ok(str_contains($node, "this.updateText('[data-idle-remaining]'") && str_contains($node, 'this.idleRemaining === 0'), 'al llegar a cero actualiza texto y consulta backend');
$ok(!preg_match('/updateIdleCountdown\(\).*?(innerHTML|replaceChildren)/s', $node), 'tick no reemplaza nodos grandes');

fwrite(STDOUT, "Web OS pending fixes smoke passed.\n");
