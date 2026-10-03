<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$shell = $read('so.php');
$node = $read('js/so-node.js');
$windows = $read('js/os-window-manager.js');
$styles = $read('css/so.css');
$controller = $read('src/Http/Controller/NodeStatusController.php');
$totpController = $read('src/Http/Controller/PersonalAwsController.php');
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

foreach (['activity-costs', 'aws', 'ec2'] as $tool) {
    $ok(str_contains($shell, 'data-os-tool="' . $tool . '"'), "{$tool} abre como herramienta interna");
}
$ok(!str_contains($shell, 'data-os-tool="s3-sync"') && !str_contains($shell, 'data-tool-title="Costos AWS"'), 'Enlaces omite sincronización S3 y el acceso redundante Costos AWS');
$ok(str_contains($shell, 'data-tool-title="Actividad y costos"'), 'Enlaces conserva Actividad y costos');
$ok(str_contains($shell, 'data-window-open="terminalWindow"') && str_contains($shell, 'data-window-open="federationWindow"'), 'Consola y Federation reutilizan ventanas internas existentes');
$ok(!str_contains($shell, 'href="aws.php" target="_blank"') && !str_contains($shell, 'href="ec2.php" target="_blank"'), 'herramientas propias no fuerzan navegación externa');
$ec2Panel = (string)file_get_contents($root . '/ec2.php');
$ok(str_contains($shell, 'href="ec2.php?surface=os"') && str_contains($ec2Panel, '$showServerConsole = $isServerConsoleSuperAdmin && !$isOsSurface;'), 'Gestión EC2 del OS reutiliza ec2.php pero excluye la terminal del servidor');
$ok(str_contains($ec2Panel, 'ec2-node-card-title') && str_contains($ec2Panel, 'os-node-dashboard ec2-instance-grid'), 'Gestión EC2 organiza filtros, EC2 y RDS en contenedores responsivos');
$ok(str_contains($ec2Panel, '<?php if ($showServerConsole): ?>'), 'terminal permanece disponible únicamente en la superficie clásica autorizada');
$ok(str_contains($windows, "this.manager.register(element, app") && str_contains($windows, "const app = 'tool-'"), 'WindowManager registra una tarea independiente por herramienta');
$ok(str_contains($windows, "page.querySelector('main')") && !str_contains($windows, '<iframe class="os-viewer-frame" title="Herramienta"'), 'cargador importa sólo main, sin iframe ni navbar global');
$ok(str_contains($windows, "form.matches('[data-os-totp-form]')") && str_contains($windows, 'submitTotpForm(body, target, data, form)'), 'Generador TOTP usa envío AJAX dentro de su ventana');
$ok(str_contains($windows, "'X-ArcadeCloud-Embed': '1'") && str_contains($windows, 'payload.result.code'), 'Generador TOTP consume resultado JSON sin navegar');
$ok(str_contains($windows, "data.set('arcadecloud_os', '1')"), 'Generador TOTP conserva marcador embebido también en POST aunque el proxy descarte headers o query');
$ok(str_contains($windows, "data.set('response_format', 'json')"), 'Generador TOTP solicita JSON explícitamente y no depende sólo del modo embebido');
$ok(str_contains($windows, "const endpoint = new URL('aws.php', this.window.location.href)") && str_contains($windows, 'fetch(endpoint.toString()'), 'Generador TOTP publica siempre al endpoint canónico aws.php y no confía en action importado');
$ok(str_contains($windows, 'response.redirected') && str_contains($windows, 'ruta final:'), 'Generador TOTP informa ruta final y redirección sin exponer semillas cuando recibe HTML');
$ok(str_contains($windows, "contentType.includes('application/json')"), 'Generador TOTP valida el contrato JSON antes de decodificar');
$ok(str_contains($totpController, "queryString('arcadecloud_os')") && str_contains($totpController, "JsonResponse::send(['ok' => false"), 'endpoint TOTP conserva JSON tras proxy y también en errores de acceso');
$ok(str_contains($totpController, "postString('arcadecloud_os')") && str_contains($totpController, "serverString('HTTP_X_ARCADECLOUD_EMBED')"), 'endpoint TOTP reconoce marcadores embebidos por header, query y POST');
$ok(str_contains($totpController, "postString('response_format')") && str_contains($totpController, '$jsonGenerate'), 'endpoint TOTP fuerza contrato JSON cuando el cliente lo solicita explícitamente');
$ok(str_contains($node, "'os-node-card os-node-card-wide'") && str_contains($styles, '.os-node-card-wide{grid-column:1/-1}'), 'Recursos ocupa todas las columnas del layout interior');

$ok(str_contains($node, 'updateIdleCountdown()') && str_contains($node, 'this.idleRemaining - 1'), 'countdown local actualiza cada segundo');
$ok(str_contains($node, 'setInterval(() => { if (this.isOpen()) this.refresh()') && str_contains($node, '}, 30000)'), 'polling real permanece en 30 segundos');
$ok(str_contains($node, "data?.scope !== 'local'") && str_contains($node, 'this.node = data.local || data.node'), 'Mi nodo sólo presenta el servidor local');
$ok(str_contains($node, "this.updateText('[data-idle-remaining]'") && str_contains($node, 'this.idleRemaining === 0'), 'al llegar a cero actualiza texto y consulta backend');
$ok(preg_match('/updateIdleCountdown\(\) \{[^}]*\}/', $node, $tick) && !preg_match('/innerHTML|replaceChildren/', $tick[0]), 'tick no reemplaza nodos grandes');

fwrite(STDOUT, "Web OS pending fixes smoke passed.\n");
