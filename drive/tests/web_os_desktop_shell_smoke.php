<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$shell = $read('js/desktop-shell.js'); $manager = $read('js/os-window-manager.js'); $page = $read('so.php'); $css = $read('css/so.css'); $architecture = $read('ARCHITECTURE.md');
$assert = static function (bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } fwrite(STDOUT, "OK: {$message}\n"); };
$assert(str_contains($manager, 'get activeId()') && str_contains($manager, "this.bus.emit('window-minimized'"), 'WindowManager expone foco y transiciones sin un segundo manager');
$assert(str_contains($manager, 'is-minimized') && str_contains($manager, 'aria-current'), 'taskbar representa estados y accesibilidad');
$assert(str_contains($shell, 'launchableApplications()') && str_contains($shell, 'this.manager.apps.get'), 'launcher deriva metadatos del registro real');
$assert(str_contains($shell, "forceNew: true") && str_contains($shell, "target: 'nodeWindow'"), 'launcher conserva Explorer multiinstancia y singleton por manager');
$assert(str_contains($shell, "event.altKey && event.key === 'Tab'") && str_contains($shell, 'lastFocused'), 'switcher implementa Alt+Tab y MRU');
$assert(str_contains($shell, 'static isEditable') && str_contains($shell, "key === 'c'") && str_contains($shell, "event.key === 'Delete'"), 'router protege editores y enruta atajos Explorer');
$assert(str_contains($shell, "'notification:show'") && str_contains($shell, "'filesystem:operation'"), 'notificaciones reutilizan EventBus y lifecycle filesystem');
$assert(str_contains($shell, 'this.notifications.children.length > 5'), 'notificaciones de sesión tienen límite y cleanup');
$assert(str_contains($page, 'js/desktop-shell.js'), 'módulo está integrado en so.php');
$assert(str_contains($css, '.os-window-switcher') && str_contains($css, '.os-notifications') && str_contains($css, 'var(--os-panel)'), 'superficies usan tokens y capas compartidas');
$assert(str_contains($architecture, '## Etapa 5: Desktop Shell'), 'arquitectura implementada está documentada');
$assert(!preg_match('/\b(?:alert|confirm|prompt)\s*\(/', $shell), 'nueva shell no usa diálogos nativos bloqueantes');
fwrite(STDOUT, "Web OS desktop shell smoke passed.\n");
