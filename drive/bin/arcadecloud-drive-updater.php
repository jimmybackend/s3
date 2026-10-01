#!/usr/bin/env php
<?php
declare(strict_types=1);

final class ArcadeCloudDriveUpdater
{
    private const CONFIG = '/etc/arcadecloud-drive/updater.json';

    public function run(array $argv): never
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->fail('El updater debe ejecutarse como root mediante sudo.', 77);
        }

        $config = $this->readConfig();
        $action = strtolower(trim((string)($argv[1] ?? 'check')));

        if ($action === 'probe') {
            fwrite(
                STDOUT,
                json_encode([
                    'ok' => true,
                    'action' => 'probe',
                    'euid' => posix_geteuid(),
                    'php_user' => (string)($config['php_user'] ?? ''),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );
            exit(0);
        }

        if ($action === 'check') {
            fwrite(
                STDOUT,
                json_encode(
                    $this->currentState($config, true),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) . "\n"
            );
            exit(0);
        }

        if (in_array($action, ['apply', 'apply-stash'], true)) {
            $before = $this->currentState($config, true);
            if (!$before['update_available']) {
                $this->fail('ArcadeCloud ya está actualizado.', 17);
            }
            if ($before['branch'] !== 'main') {
                $this->fail('La actualización web sólo está permitida desde la rama main.', 17);
            }
            if ($before['ahead'] > 0) {
                $this->fail('El repositorio local tiene commits no publicados.', 17);
            }

            $stashCreated = false;
            $stashMessage = '';

            if ($before['dirty']) {
                if ($action !== 'apply-stash') {
                    $this->fail(
                        'El repositorio tiene cambios locales. Usa la opción web para guardarlos temporalmente y actualizar.',
                        17
                    );
                }

                $label = 'arcadecloud-web-update-'
                    . gmdate('Ymd-His')
                    . '-'
                    . substr((string)$before['local_commit'], 0, 12);

                $stash = $this->git($config, [
                    'stash', 'push', '--include-untracked', '-m', $label,
                ]);
                $stashMessage = trim((string)$stash['stdout']);
                $stashCreated = !str_contains(strtolower($stashMessage), 'no local changes to save');

                $clean = $this->currentState($config, false);
                if ($clean['dirty']) {
                    $this->fail(
                        'No fue posible dejar limpio el checkout después de guardar los cambios locales. '
                        . 'Revisa permisos/archivos del repositorio.',
                        17
                    );
                }
            } elseif (!$before['can_apply']) {
                $this->fail('La actualización no es fast-forward segura.', 17);
            }

            $this->git($config, ['merge-base', '--is-ancestor', 'HEAD', 'origin/main']);
            $this->git($config, ['merge', '--ff-only', 'origin/main']);
            $after = $this->currentState($config, false);

            if ($after['behind'] !== 0 || $after['dirty']) {
                $this->fail('La actualización terminó en un estado inesperado.', 70);
            }

            $reconcile = $this->reconcileServices($config);
            $message = $reconcile['ok']
                ? 'ArcadeCloud se actualizó y sus servicios quedaron reconciliados.'
                : 'El código quedó actualizado, pero un servicio necesita revisión: ' . $reconcile['message'];
            if ($stashCreated) {
                $message .= ' Los cambios locales anteriores quedaron guardados en git stash y no se restauraron automáticamente.';
            }

            fwrite(
                STDOUT,
                json_encode(array_merge($after, [
                    'ok' => true,
                    'updated' => true,
                    'previous_commit' => $before['local_commit'],
                    'stash_created' => $stashCreated,
                    'stash_message' => $stashMessage,
                    'reconcile_ok' => $reconcile['ok'],
                    'needs_attention' => !$reconcile['ok'],
                    'reconcile_message' => $reconcile['message'],
                    'message' => $message,
                ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );
            exit(0);
        }

        $this->fail('Acción no permitida.', 64);
    }

    private function fail(string $message, int $code = 1): never
    {
        fwrite(STDERR, $message . "\n");
        exit($code);
    }

    private function readConfig(): array
    {
        if (!is_file(self::CONFIG) || !is_readable(self::CONFIG)) {
            $this->fail('Updater no configurado: falta ' . self::CONFIG . '.');
        }

        $data = json_decode((string)file_get_contents(self::CONFIG), true);
        if (!is_array($data)) {
            $this->fail('Configuración del updater inválida.');
        }

        return $data;
    }

    private function runAs(string $user, array $command, ?int $expectedExit = 0): array
    {
        if (!function_exists('proc_open')) {
            $this->fail('proc_open no está disponible.');
        }

        $cmd = array_merge(['/usr/sbin/runuser', '-u', $user, '--'], $command);
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            $this->fail('No se pudo ejecutar el updater.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 131073);
        $stderr = stream_get_contents($pipes[2], 32769);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if (!is_string($stdout) || strlen($stdout) > 131072
            || !is_string($stderr) || strlen($stderr) > 32768) {
            $this->fail('Respuesta del updater demasiado grande.');
        }

        if ($expectedExit !== null && $exit !== $expectedExit) {
            $this->fail(
                trim($stderr) !== '' ? trim($stderr) : 'Git rechazó la operación.',
                $exit > 0 ? $exit : 1
            );
        }

        return [
            'exit' => $exit,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ];
    }

    private function git(array $config, array $args, ?int $expectedExit = 0): array
    {
        return $this->runAs(
            (string)$config['repo_user'],
            array_merge(['/usr/bin/git', '-C', (string)$config['repo_root']], $args),
            $expectedExit
        );
    }

    private function assertRepository(array $config): void
    {
        $root = (string)($config['repo_root'] ?? '');
        $user = (string)($config['repo_user'] ?? '');

        if ($root === '' || $root[0] !== '/' || !is_dir($root . '/.git')) {
            $this->fail('Repositorio ArcadeCloud inválido.');
        }
        if ($user === '' || !preg_match('/\A[a-z_][a-z0-9_-]*[$]?\z/i', $user)) {
            $this->fail('Usuario del repositorio inválido.');
        }

        $phpUser = trim((string)($config['php_user'] ?? ''));
        if ($phpUser === '' || !preg_match('/\A[a-z_][a-z0-9_-]*[$]?\z/i', $phpUser)) {
            $this->fail('Usuario PHP-FPM inválido en la configuración del updater.');
        }

        $remote = $this->git($config, ['remote', 'get-url', 'origin'])['stdout'];
        $allowed = [
            'https://github.com/jimmybackend/s3.git',
            'https://github.com/jimmybackend/s3',
            'git@github.com:jimmybackend/s3.git',
            'ssh://git@github.com/jimmybackend/s3.git',
        ];

        if (!in_array($remote, $allowed, true)) {
            $this->fail('El remoto origin no corresponde al repositorio oficial jimmybackend/s3.');
        }
    }

    private function reconcileServices(array $config): array
    {
        $script = rtrim((string)$config['repo_root'], '/')
            . '/drive/bin/reconcile_arcadecloud_services.sh';
        if (!is_file($script) || !is_readable($script)) {
            return ['ok' => false, 'message' => 'falta el reconciliador de servicios del repositorio actualizado'];
        }

        // No reutilizar php_user guardado: puede haberse quedado obsoleto.
        // El reconciliador detecta el usuario del pool real de Drive (puerto 9075)
        // y vuelve a instalar updater/helper con ese usuario.
        $cmd = [
            '/bin/bash',
            $script,
            '--app-root=' . (string)$config['repo_root'],
            '--defer-php-restart',
        ];
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'message' => 'no se pudo iniciar la reconciliación'];
        }

        $stdout = stream_get_contents($pipes[1], 32769);
        $stderr = stream_get_contents($pipes[2], 32769);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $detail = trim((string)($stderr !== '' ? $stderr : $stdout));
        if (strlen($detail) > 1000) {
            $detail = substr($detail, -1000);
        }

        return [
            'ok' => $exit === 0,
            'message' => $exit === 0
                ? 'servicios reconciliados'
                : ($detail !== '' ? $detail : 'la reconciliación devolvió código ' . $exit),
        ];
    }

    private function currentState(array $config, bool $fetch): array
    {
        $this->assertRepository($config);

        $branch = $this->git($config, ['rev-parse', '--abbrev-ref', 'HEAD'])['stdout'];
        $local = $this->git($config, ['rev-parse', 'HEAD'])['stdout'];
        $dirtyOutput = $this->git($config, ['status', '--porcelain=v1', '--untracked-files=all'])['stdout'];
        $dirty = $dirtyOutput !== '';
        $dirtyFiles = $dirtyOutput === ''
            ? []
            : array_slice(preg_split('/\R/', $dirtyOutput) ?: [], 0, 25);

        if ($fetch) {
            $this->git($config, ['fetch', '--quiet', '--prune', 'origin', 'main']);
        }

        $remote = $this->git($config, ['rev-parse', 'origin/main'])['stdout'];
        $behind = max(0, (int)$this->git($config, ['rev-list', '--count', 'HEAD..origin/main'])['stdout']);
        $ahead = max(0, (int)$this->git($config, ['rev-list', '--count', 'origin/main..HEAD'])['stdout']);
        $summary = [];
        $repoHelper = rtrim((string)$config['repo_root'], '/') . '/drive/bin/arcadecloud-drive-updater.php';
        $installedHash = is_file(__FILE__) ? hash_file('sha256', __FILE__) : false;
        $repoHash = is_file($repoHelper) ? hash_file('sha256', $repoHelper) : false;
        $helperInSync = is_string($installedHash) && is_string($repoHash)
            && hash_equals($installedHash, $repoHash);

        if ($behind > 0) {
            $log = $this->git($config, [
                'log', '--format=%h %s', '--max-count=8', 'HEAD..origin/main',
            ])['stdout'];
            if ($log !== '') {
                $summary = preg_split('/\R/', $log) ?: [];
            }
        }

        return [
            'ok' => true,
            'branch' => $branch,
            'local_commit' => $local,
            'remote_commit' => $remote,
            'dirty' => $dirty,
            'dirty_files' => array_values(array_filter(array_map('strval', $dirtyFiles))),
            'behind' => $behind,
            'ahead' => $ahead,
            'update_available' => $behind > 0,
            'can_apply' => $branch === 'main' && !$dirty && $ahead === 0 && $behind > 0,
            'can_apply_with_stash' => $branch === 'main' && $dirty && $ahead === 0 && $behind > 0,
            'summary' => array_values(array_filter(array_map('strval', $summary))),
            'helper_in_sync' => $helperInSync,
            'helper_installed_sha256' => is_string($installedHash) ? $installedHash : '',
            'helper_repo_sha256' => is_string($repoHash) ? $repoHash : '',
            'checked_at' => gmdate(DATE_ATOM),
        ];
    }
}

(new ArcadeCloudDriveUpdater())->run($argv);
