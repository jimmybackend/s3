#!/usr/bin/env php
<?php
declare(strict_types=1);

const AC_UPDATE_CONFIG = '/etc/arcadecloud-drive/updater.json';

function fail(string $message, int $code = 1): never { fwrite(STDERR, $message . "\n"); exit($code); }

function readConfig(): array
{
    if (!is_file(AC_UPDATE_CONFIG) || !is_readable(AC_UPDATE_CONFIG)) fail('Updater no configurado: falta ' . AC_UPDATE_CONFIG . '.');
    $data = json_decode((string)file_get_contents(AC_UPDATE_CONFIG), true);
    if (!is_array($data)) fail('Configuración del updater inválida.');
    return $data;
}

function runAs(string $user, array $command, ?int $expectedExit = 0): array
{
    if (!function_exists('proc_open')) fail('proc_open no está disponible.');
    $cmd = array_merge(['/usr/sbin/runuser', '-u', $user, '--'], $command);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) fail('No se pudo ejecutar el updater.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], 131073);
    $stderr = stream_get_contents($pipes[2], 32769);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    if (!is_string($stdout) || strlen($stdout) > 131072 || !is_string($stderr) || strlen($stderr) > 32768) fail('Respuesta del updater demasiado grande.');
    if ($expectedExit !== null && $exit !== $expectedExit) fail(trim($stderr) !== '' ? trim($stderr) : 'Git rechazó la operación.', $exit > 0 ? $exit : 1);
    return ['exit' => $exit, 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
}

function git(array $config, array $args, ?int $expectedExit = 0): array
{
    return runAs((string)$config['repo_user'], array_merge(['/usr/bin/git', '-C', (string)$config['repo_root']], $args), $expectedExit);
}

function assertRepository(array $config): void
{
    $root = (string)($config['repo_root'] ?? '');
    $user = (string)($config['repo_user'] ?? '');
    if ($root === '' || $root[0] !== '/' || !is_dir($root . '/.git')) fail('Repositorio ArcadeCloud inválido.');
    if ($user === '' || !preg_match('/\A[a-z_][a-z0-9_-]*[$]?\z/i', $user)) fail('Usuario del repositorio inválido.');
    $remote = git($config, ['remote', 'get-url', 'origin'])['stdout'];
    $allowed = [
        'https://github.com/jimmybackend/s3.git', 'https://github.com/jimmybackend/s3',
        'git@github.com:jimmybackend/s3.git', 'ssh://git@github.com/jimmybackend/s3.git'
    ];
    if (!in_array($remote, $allowed, true)) fail('El remoto origin no corresponde al repositorio oficial jimmybackend/s3.');
}

function currentState(array $config, bool $fetch): array
{
    assertRepository($config);
    $branch = git($config, ['rev-parse', '--abbrev-ref', 'HEAD'])['stdout'];
    $local = git($config, ['rev-parse', 'HEAD'])['stdout'];
    $dirty = git($config, ['status', '--porcelain'])['stdout'] !== '';
    if ($fetch) git($config, ['fetch', '--quiet', '--prune', 'origin', 'main']);
    $remote = git($config, ['rev-parse', 'origin/main'])['stdout'];
    $behind = max(0, (int)git($config, ['rev-list', '--count', 'HEAD..origin/main'])['stdout']);
    $ahead = max(0, (int)git($config, ['rev-list', '--count', 'origin/main..HEAD'])['stdout']);
    $summary = [];
    if ($behind > 0) {
        $log = git($config, ['log', '--format=%h %s', '--max-count=8', 'HEAD..origin/main'])['stdout'];
        if ($log !== '') $summary = preg_split('/\R/', $log) ?: [];
    }
    return [
        'ok' => true,
        'branch' => $branch,
        'local_commit' => $local,
        'remote_commit' => $remote,
        'dirty' => $dirty,
        'behind' => $behind,
        'ahead' => $ahead,
        'update_available' => $behind > 0,
        'can_apply' => $branch === 'main' && !$dirty && $ahead === 0 && $behind > 0,
        'summary' => array_values(array_filter(array_map('strval', $summary))),
        'checked_at' => gmdate(DATE_ATOM),
    ];
}

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) fail('El updater debe ejecutarse como root mediante sudo.', 77);
$config = readConfig();
$action = strtolower(trim((string)($argv[1] ?? 'check')));

if ($action === 'check') {
    fwrite(STDOUT, json_encode(currentState($config, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}

if ($action === 'apply') {
    $before = currentState($config, true);
    if (!$before['update_available']) fail('ArcadeCloud ya está actualizado.', 17);
    if (!$before['can_apply']) {
        if ($before['branch'] !== 'main') fail('La actualización web sólo está permitida desde la rama main.', 17);
        if ($before['dirty']) fail('El repositorio tiene cambios locales. No se actualizará automáticamente.', 17);
        if ($before['ahead'] > 0) fail('El repositorio local tiene commits no publicados.', 17);
        fail('La actualización no es fast-forward segura.', 17);
    }
    git($config, ['merge-base', '--is-ancestor', 'HEAD', 'origin/main']);
    git($config, ['merge', '--ff-only', 'origin/main']);
    $after = currentState($config, false);
    if ($after['behind'] !== 0 || $after['dirty']) fail('La actualización terminó en un estado inesperado.', 70);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'updated' => true,
        'previous_commit' => $before['local_commit'],
        'local_commit' => $after['local_commit'],
        'message' => 'ArcadeCloud se actualizó mediante fast-forward seguro. Recarga la página.'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}

fail('Acción no permitida.', 64);
