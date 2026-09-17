#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ArcadeCloud Drive repository updater.
 * Installed root:root as /usr/local/sbin/arcadecloud-drive-updater.
 * It accepts only status, check and update <expected-sha>.
 */

const AC_UPDATER_CONFIG = '/etc/arcadecloud-drive/updater.json';
const AC_UPDATER_LOCK = '/run/arcadecloud-drive-updater.lock';
const AC_GIT = '/usr/bin/git';
const AC_SUDO = '/usr/bin/sudo';
const AC_ENV = '/usr/bin/env';
const AC_TIMEOUT = '/usr/bin/timeout';
const AC_SYSTEMD_RUN = '/usr/bin/systemd-run';
const AC_SYSTEMCTL = '/usr/bin/systemctl';

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function isRoot(): bool
{
    return function_exists('posix_geteuid') && posix_geteuid() === 0;
}

function readConfig(): array
{
    if (!is_file(AC_UPDATER_CONFIG) || !is_readable(AC_UPDATER_CONFIG)) {
        fail('Updater no configurado: falta ' . AC_UPDATER_CONFIG . '.');
    }
    $decoded = json_decode((string)file_get_contents(AC_UPDATER_CONFIG), true);
    if (!is_array($decoded) || array_is_list($decoded)) fail('Configuración del updater inválida.');
    return $decoded;
}

function configString(array $config, string $key, bool $required = true): string
{
    $value = trim((string)($config[$key] ?? ''));
    if ($required && $value === '') fail('Falta configuración: ' . $key . '.');
    if (str_contains($value, "\0")) fail('Configuración inválida: ' . $key . '.');
    return $value;
}

function validateConfig(array $config): array
{
    $repoRoot = configString($config, 'repo_root');
    $repoUser = configString($config, 'repo_user');
    $branch = configString($config, 'branch');
    $publicUrl = configString($config, 'public_url');
    $phpService = configString($config, 'php_service', false);

    if ($repoRoot[0] !== '/' || str_contains($repoRoot, '/../')) fail('repo_root inválido.');
    if (!is_dir($repoRoot . '/.git')) fail('repo_root no contiene un checkout Git válido.');
    if (!preg_match('/\A[a-z_][a-z0-9_-]*\z/i', $repoUser)) fail('repo_user inválido.');
    if (!preg_match('/\A[a-zA-Z0-9._\/-]+\z/', $branch)) fail('branch inválida.');
    if (!preg_match('#\Ahttps://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?\z#', $publicUrl)) {
        fail('public_url debe ser un repositorio HTTPS de github.com.');
    }
    if ($phpService !== '' && !preg_match('/\A[a-zA-Z0-9@_.-]+\z/', $phpService)) fail('php_service inválido.');

    return [
        'repo_root' => $repoRoot,
        'repo_user' => $repoUser,
        'branch' => $branch,
        'public_url' => $publicUrl,
        'php_service' => $phpService,
    ];
}

function runProcess(array $command, int $maxStdout = 65536, int $maxStderr = 16384): array
{
    if (!function_exists('proc_open')) fail('proc_open no está disponible.', 69);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) fail('No se pudo iniciar el proceso requerido.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], $maxStdout + 1);
    $stderr = stream_get_contents($pipes[2], $maxStderr + 1);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if (!is_string($stdout) || strlen($stdout) > $maxStdout || !is_string($stderr) || strlen($stderr) > $maxStderr) {
        fail('Respuesta del proceso demasiado grande.');
    }
    return ['exit_code' => $exit, 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
}

function gitCommand(array $config, array $args, int $timeoutSeconds = 25): array
{
    if (!is_executable(AC_GIT) || !is_executable(AC_SUDO) || !is_executable(AC_ENV) || !is_executable(AC_TIMEOUT)) {
        fail('Faltan utilidades requeridas para Git.');
    }
    $command = [
        AC_SUDO, '-u', $config['repo_user'], '-H',
        AC_ENV,
        'GIT_TERMINAL_PROMPT=0',
        'GIT_SSH_COMMAND=ssh -o BatchMode=yes -o ConnectTimeout=8',
        AC_TIMEOUT, max(5, min(60, $timeoutSeconds)) . 's',
        AC_GIT, '-C', $config['repo_root'],
    ];
    foreach ($args as $arg) $command[] = (string)$arg;
    return runProcess($command);
}

function gitValue(array $config, array $args): string
{
    $result = gitCommand($config, $args);
    if ((int)$result['exit_code'] !== 0) {
        $message = trim((string)$result['stderr']);
        fail($message !== '' ? $message : 'Git rechazó la operación.');
    }
    return trim((string)$result['stdout']);
}

function fetchRemote(array $config): array
{
    $branch = $config['branch'];
    $origin = gitCommand($config, ['fetch', '--quiet', '--no-tags', 'origin', $branch], 30);
    if ((int)$origin['exit_code'] === 0) {
        return ['ref' => 'refs/remotes/origin/' . $branch, 'source' => 'origin'];
    }

    $fallbackRef = 'refs/remotes/arcadecloud-updater/' . $branch;
    $fallback = gitCommand($config, [
        'fetch', '--quiet', '--no-tags', $config['public_url'],
        '+refs/heads/' . $branch . ':' . $fallbackRef,
    ], 30);
    if ((int)$fallback['exit_code'] !== 0) {
        $message = trim((string)$fallback['stderr']);
        if ($message === '') $message = trim((string)$origin['stderr']);
        fail($message !== '' ? $message : 'No se pudo consultar el repositorio remoto.', 75);
    }
    return ['ref' => $fallbackRef, 'source' => 'public'];
}

function localState(array $config): array
{
    $inside = gitValue($config, ['rev-parse', '--is-inside-work-tree']);
    if ($inside !== 'true') fail('La ruta configurada no es un checkout Git válido.');

    $branchResult = gitCommand($config, ['symbolic-ref', '--quiet', '--short', 'HEAD']);
    $branch = (int)$branchResult['exit_code'] === 0 ? trim((string)$branchResult['stdout']) : '(detached)';
    $localSha = gitValue($config, ['rev-parse', 'HEAD']);
    $localShort = gitValue($config, ['rev-parse', '--short=7', 'HEAD']);
    $localSubject = gitValue($config, ['log', '-1', '--format=%s', 'HEAD']);
    $dirtyText = gitValue($config, ['status', '--porcelain', '--untracked-files=normal']);

    return [
        'branch' => $branch,
        'local_sha' => $localSha,
        'local_short' => $localShort,
        'local_subject' => $localSubject,
        'clean' => $dirtyText === '',
        'dirty_entries' => $dirtyText === '' ? [] : array_slice(preg_split('/\R/', $dirtyText) ?: [], 0, 20),
    ];
}

function repositoryState(array $config, bool $fetch): array
{
    $local = localState($config);
    if (!$fetch) return $local;

    $remote = fetchRemote($config);
    $remoteSha = gitValue($config, ['rev-parse', $remote['ref']]);
    $remoteShort = gitValue($config, ['rev-parse', '--short=7', $remote['ref']]);
    $remoteSubject = gitValue($config, ['log', '-1', '--format=%s', $remote['ref']]);
    $counts = gitValue($config, ['rev-list', '--left-right', '--count', 'HEAD...' . $remote['ref']]);
    $parts = preg_split('/\s+/', trim($counts)) ?: [];
    $ahead = isset($parts[0]) ? (int)$parts[0] : 0;
    $behind = isset($parts[1]) ? (int)$parts[1] : 0;

    $commitLines = [];
    if ($behind > 0) {
        $log = gitValue($config, ['log', '--format=%h%x09%s', '--max-count=12', 'HEAD..' . $remote['ref']]);
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if ($line === '') continue;
            [$sha, $subject] = array_pad(explode("\t", $line, 2), 2, '');
            $commitLines[] = ['sha' => $sha, 'subject' => $subject];
        }
    }

    $available = $behind > 0;
    $blockers = [];
    if ($local['branch'] !== $config['branch']) $blockers[] = 'El checkout no está en la rama ' . $config['branch'] . '.';
    if (!$local['clean']) $blockers[] = 'El checkout tiene cambios locales sin confirmar.';
    if ($ahead > 0) $blockers[] = 'El checkout contiene commits locales que no están en el remoto.';

    return $local + [
        'ok' => true,
        'checked_at' => gmdate(DATE_ATOM),
        'remote_sha' => $remoteSha,
        'remote_short' => $remoteShort,
        'remote_subject' => $remoteSubject,
        'remote_source' => $remote['source'],
        'ahead' => $ahead,
        'behind' => $behind,
        'update_available' => $available,
        'can_update' => $available && $blockers === [],
        'blockers' => $blockers,
        'commits' => $commitLines,
    ];
}

function schedulePhpRestart(array $config): bool
{
    $service = trim((string)$config['php_service']);
    if ($service === '' || !is_executable(AC_SYSTEMD_RUN) || !is_executable(AC_SYSTEMCTL)) return false;
    $unit = 'arcadecloud-drive-update-restart-' . gmdate('YmdHis');
    $result = runProcess([
        AC_SYSTEMD_RUN,
        '--quiet',
        '--unit=' . $unit,
        '--on-active=5s',
        AC_SYSTEMCTL,
        'restart',
        $service,
    ]);
    return (int)$result['exit_code'] === 0;
}

if (!isRoot()) fail('Este updater debe ejecutarse como root mediante sudo.', 77);
$config = validateConfig(readConfig());
$action = strtolower(trim((string)($argv[1] ?? 'status')));

if ($action === 'status') {
    $state = repositoryState($config, false);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'version' => 1,
        'capabilities' => ['repository_check' => true, 'repository_update' => true],
        'repo_root' => $config['repo_root'],
        'repo_user' => $config['repo_user'],
        'branch' => $state['branch'],
        'local_sha' => $state['local_sha'],
        'local_short' => $state['local_short'],
        'clean' => $state['clean'],
    ], JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

if ($action === 'check') {
    fwrite(STDOUT, json_encode(repositoryState($config, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}

if ($action === 'update') {
    $expected = strtolower(trim((string)($argv[2] ?? '')));
    if (!preg_match('/\A[a-f0-9]{40}\z/', $expected)) fail('SHA remoto esperado inválido.', 64);

    $lock = fopen(AC_UPDATER_LOCK, 'c+');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) fail('Ya hay una actualización de ArcadeCloud en curso.', 75);

    try {
        $before = repositoryState($config, true);
        if (!hash_equals($expected, (string)$before['remote_sha'])) {
            fail('El repositorio remoto cambió desde la última comprobación. Vuelve a buscar actualizaciones.', 75);
        }
        if (($before['can_update'] ?? false) !== true) {
            $blockers = is_array($before['blockers'] ?? null) ? implode(' ', $before['blockers']) : '';
            fail($blockers !== '' ? $blockers : 'La actualización no puede aplicarse de forma segura.', 75);
        }

        $remoteRef = $before['remote_source'] === 'origin'
            ? 'refs/remotes/origin/' . $config['branch']
            : 'refs/remotes/arcadecloud-updater/' . $config['branch'];
        $merge = gitCommand($config, ['merge', '--ff-only', $remoteRef], 30);
        if ((int)$merge['exit_code'] !== 0) {
            $message = trim((string)$merge['stderr']);
            fail($message !== '' ? $message : 'No se pudo aplicar el fast-forward.', 75);
        }

        $after = repositoryState($config, false);
        $restartScheduled = schedulePhpRestart($config);
        fwrite(STDOUT, json_encode([
            'ok' => true,
            'updated' => true,
            'previous_sha' => $before['local_sha'],
            'previous_short' => $before['local_short'],
            'new_sha' => $after['local_sha'],
            'new_short' => $after['local_short'],
            'php_restart_scheduled' => $restartScheduled,
            'restart_delay_seconds' => $restartScheduled ? 5 : 0,
        ], JSON_UNESCAPED_SLASHES) . "\n");
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    exit(0);
}

fail('Acción no permitida.', 64);
