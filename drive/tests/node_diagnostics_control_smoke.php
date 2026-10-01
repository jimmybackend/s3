<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/System/NodeServiceCatalog.php';

use ArcadeCloud\Drive\System\NodeServiceCatalog;

function nodeContract(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "OK: {$message}\n");
}

$catalog = NodeServiceCatalog::COMPONENTS;
$runtime = (string)file_get_contents(dirname(__DIR__) . '/src/System/NodeRuntimeStatusService.php');
$control = (string)file_get_contents(dirname(__DIR__) . '/src/Admin/NodeServiceControlService.php');
$helper = (string)file_get_contents(dirname(__DIR__) . '/bin/arcadecloud-drive-admin-helper.php');
$controller = (string)file_get_contents(dirname(__DIR__) . '/src/Http/Controller/NodeStatusController.php');
$js = (string)file_get_contents(dirname(__DIR__) . '/js/so-node.js');

nodeContract($catalog['nginx']['critical'] && $catalog['nginx']['allowed_actions'] === [], 'Nginx crítico no ofrece Stop');
nodeContract($catalog['php-fpm']['critical'] && $catalog['php-fpm']['allowed_actions'] === [], 'PHP-FPM crítico es sólo lectura');
nodeContract(in_array('run-now', $catalog['federation-sync']['allowed_actions'], true), 'Federation Sync permite reintento controlado');
nodeContract($catalog['polly-reconcile']['type'] === 'oneshot' && $catalog['transcribe-reconcile']['type'] === 'oneshot', 'reconciliadores se clasifican one-shot');
nodeContract($catalog['polly-reconcile-timer']['type'] === 'timer', 'timer conserva próxima ejecución');
nodeContract(str_contains($runtime, "'healthy_idle' => \$healthyIdle"), 'static/inactive exitoso se considera espera saludable');
nodeContract(str_contains($runtime, "if (\$s['critical'] ?? false)"), 'salud central diferencia fallo crítico y opcional');
nodeContract(str_contains($runtime, "'permission_denied'"), 'nginx -t sin permiso queda separado del servicio');
nodeContract(str_contains($runtime, "journalctl', '-u', \$unit, '-n', '5'"), 'diagnóstico limita journal a cinco líneas');
nodeContract(str_contains($runtime, '[REDACTADO]'), 'diagnóstico redacta secretos');
nodeContract(str_contains($control, 'NodeServiceCatalog::component'), 'acciones validan whitelist por ID opaco');
nodeContract(str_contains($control, 'SuperAdminReauthenticationService'), 'acciones reutilizan reautenticación superadmin');
nodeContract(str_contains($helper, "if (!isset(\$catalog[\$componentId])"), 'helper root vuelve a validar catálogo y acción');
nodeContract(!str_contains($controller, "postString('service')"), 'frontend no puede enviar nombres de unit systemd');
nodeContract(str_contains($helper, "'start', '--no-block', \$unit"), 'run-now no bloquea PHP');
nodeContract(str_contains($runtime, "['stopped', 'stopping']"), 'FastDrive detenido conserva salud neutral');
nodeContract(str_contains($runtime, "'idle_timeout_seconds'=>\$timeout"), 'autoapagado usa timeout real del backend');
nodeContract(str_contains($js, 'else this.stopPolling()'), 'polling se detiene al cerrar Mi nodo');
nodeContract(str_contains($js, "s.critical") === false, 'UI no deriva controles críticos de datos libres');

fwrite(STDOUT, "Node diagnostics/control smoke passed.\n");
