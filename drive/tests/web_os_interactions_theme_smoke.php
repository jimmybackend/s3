<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);
$windows = $read('js/os-window-manager.js');
$shell = $read('so.php');
$clipboard = $read('js/so-clipboard.js');
$styles = $read('css/so.css');
$federation = $read('js/so-federation.js');
$ok = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "OK: {$message}\n");
};

$ok(str_contains($windows, 'beginWindowDrag(event, element, handle)') && str_contains($windows, "handle.addEventListener('pointerdown'"), 'chrome dinámico enlaza pointer drag desde el runtime');
$ok(str_contains($windows, "element.style.left =") && str_contains($windows, "element.style.top =") && str_contains($windows, 'record.preferredGeometry'), 'drag consolida left/top y geometría del registro');
$ok(str_contains($windows, "event.target.closest('button,input,select,textarea,a,[contenteditable=\"true\"]')"), 'controles interactivos no inician drag');
$ok(str_contains($shell, 'data-selection-action="cut"') && str_contains($shell, '> Cortar</button>'), 'toolbar expone Cortar');
$ok(str_contains($clipboard, "operation = mode === 'copy' ? 'copy' : 'cut'") && str_contains($clipboard, "operation: item.mode"), 'clipboard conserva intención cut y traduce al contrato move del backend');
$ok(str_contains($clipboard, "moving ? 'Mover aquí' : 'Copiar aquí'"), 'destinos muestran la operación pendiente');
$ok(!str_contains($windows, 'this.window.prompt(`Transferir') && str_contains($windows, "className = 'os-decision-dialog'") && str_contains($windows, "data-os-decision=\"cancel\""), 'drag/drop usa diálogo del OS con cancelación');
$ok(str_contains($styles, '.os-decision-dialog') && str_contains($styles, 'var(--os-surface)'), 'diálogo de transferencia usa tokens del tema claro/oscuro');
$ok(str_contains($styles, '.os-tool-body>.os-app') && str_contains($styles, 'var(--os-input-bg)') && str_contains($styles, '.os-federation-app'), 'apps internas comparten tokens vivos del OS');
$ok(!str_contains($shell, 'id="federationFrame"') && str_contains($shell, 'id="federationApp"'), 'FederationCloud se integra sin iframe');
$ok(str_contains($windows, 'index\\.php') && str_contains($windows, 'intentó abandonar ArcadeCloud OS'), 'cargador bloquea redirección de herramienta al index');
$ok(str_contains($shell, 'name="terminal_command"') && str_contains($shell, 'value=""') && str_contains($shell, 'Escribe un comando permitido…'), 'terminal usa semántica de comando y valor vacío');

fwrite(STDOUT, "Web OS interactions/theme smoke passed.\n");
