<?php
declare(strict_types=1);

$container = getenv('ARCADECLOUD_WORKSTATION_CONTAINER') ?: 'arcadecloud-workstation';
$checks = [
    'docker' => trim((string)shell_exec('command -v docker 2>/dev/null')),
    'container' => '',
    'novnc_local' => false,
];

if ($checks['docker'] !== '') {
    $name = escapeshellarg($container);
    $checks['container'] = trim((string)shell_exec(
        "docker inspect -f '{{.State.Status}}' {$name} 2>/dev/null"
    ));
}

$socket = @fsockopen('127.0.0.1', 6080, $errno, $errstr, 1.0);
if (is_resource($socket)) {
    $checks['novnc_local'] = true;
    fclose($socket);
}

$ok = $checks['docker'] !== ''
    && $checks['container'] === 'running'
    && $checks['novnc_local'] === true;

fwrite(STDOUT, json_encode([
    'ok' => $ok,
    'service' => 'arcadecloud-workstation',
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

exit($ok ? 0 : 1);
