#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Helper privilegiado mínimo de ArcadeCloud Drive.
 * Se instala root:root en /usr/local/sbin/arcadecloud-drive-admin.
 * Nunca ejecuta comandos arbitrarios: sólo acciones y variables compiladas.
 *
 * Debe permanecer autocontenido porque el instalador copia este archivo fuera
 * del repositorio. La lógica está encapsulada en una clase sin dependencias de src/.
 */
final class ArcadeCloudDriveAdminHelper
{
    private const CONFIG = '/etc/arcadecloud-drive/admin-helper.json';
    private const RUNTIME_ENV_DEFAULT = '/etc/arcadecloud-drive/runtime-env.json';
    private const IDENTITY_DEFAULT = '/etc/arcadecloud-drive/federation-node.json';
    private const BOOTSTRAP_AUTH_DEFAULT = '/etc/arcadecloud-drive/bootstrap-auth.json';
    private const SETUP_LOCK_DEFAULT = '/etc/arcadecloud-drive/setup.lock';

    private const ENV_ALLOWLIST = [
        'ARCADECLOUD_PUBLIC_URL', 'ARCADECLOUD_FEDERATION_URL', 'ARCADECLOUD_FEDERATION_ENABLED',
        'ARCADECLOUD_FEDERATION_SEED_URL', 'ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL',
        'ARCADECLOUD_FEDERATION_REPLICA_ROLE', 'ARCADECLOUD_FEDERATION_REPLICA_SCOPE',
        'ARCADECLOUD_FEDERATION_DYNAMIC_IP', 'ARCADECLOUD_TLS_TERMINATION',
        'ARCADECLOUD_NODE_ROLE', 'ARCADECLOUD_MEDIA_WORKER',
        'ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID', 'ARCADECLOUD_MEDIA_WORKER_REGION',
        'ARCADECLOUD_MEDIA_WORKER_HOURLY_USD', 'ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS',
        'ARCADECLOUD_FASTDRIVE_INSTANCE_ID', 'ARCADECLOUD_FASTDRIVE_REGION',
        'ARCADECLOUD_DROP_ENABLED', 'ARCADECLOUD_DROP_PUBLIC_URL', 'ARCADECLOUD_DROP_COMMERCE_URL',
        'ARCADECLOUD_STRIPE_SECRET_KEY', 'ARCADECLOUD_STRIPE_WEBHOOK_SECRET', 'ARCADECLOUD_DROP_CURRENCY',
        'ARCADECLOUD_DROP_BASE_FEE_CENTS', 'ARCADECLOUD_DROP_STORAGE_GB_DAY_CENTS',
        'ARCADECLOUD_DROP_EGRESS_GB_CENTS', 'ARCADECLOUD_DROP_PENDING_HOURS',
        'ARCADECLOUD_DROP_MAX_DAYS', 'ARCADECLOUD_DROP_MAX_DOWNLOADS',
        'ARCADECLOUD_DROP_MAX_FILE_BYTES',
        'ARCADECLOUD_DROP_GOOGLE_ENABLED', 'ARCADECLOUD_DROP_GOOGLE_CLIENT_ID',
        'ARCADECLOUD_DROP_GOOGLE_CLIENT_SECRET', 'ARCADECLOUD_DROP_GOOGLE_SESSION_SECRET',
        'ARCADECLOUD_DROP_GOOGLE_SESSION_TTL',
        'ARCADECLOUD_SMTP_HOST', 'ARCADECLOUD_SMTP_PORT',
        'ARCADECLOUD_SMTP_SECURE', 'ARCADECLOUD_SMTP_USERNAME', 'ARCADECLOUD_SMTP_PASSWORD',
        'ARCADECLOUD_SMTP_FROM_EMAIL', 'ARCADECLOUD_SMTP_FROM_NAME', 'ARCADECLOUD_SMTP_REPLY_TO',
        'ARCADECLOUD_SMTP_BCC', 'ARCADECLOUD_SMTP_TIMEOUT', 'ARCADECLOUD_SMTP_DEBUG',
        'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME',
        'AWS_REGION', 'AWS_S3_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN',
        'AWS_CONTROL_ACCESS_KEY_ID', 'AWS_CONTROL_SECRET_ACCESS_KEY', 'AWS_CONTROL_SESSION_TOKEN',
    ];

    public function run(array $argv): never
    {
        if (!$this->isRoot()) {
            $this->fail('Este helper debe ejecutarse como root mediante sudo y requiere la extensión POSIX.', 77);
        }
        if (!extension_loaded('sodium')) {
            $this->fail('PHP sodium es obligatorio.', 69);
        }

        $config = $this->readConfig();
        $runtimePath = $this->safeConfiguredPath($config, 'runtime_env_path', self::RUNTIME_ENV_DEFAULT);
        $identityPath = $this->safeConfiguredPath($config, 'identity_path', self::IDENTITY_DEFAULT);
        $bootstrapAuthPath = $this->safeConfiguredPath(
            $config,
            'bootstrap_auth_path',
            self::BOOTSTRAP_AUTH_DEFAULT
        );
        $setupLockPath = $this->safeConfiguredPath($config, 'setup_lock_path', self::SETUP_LOCK_DEFAULT);
        $phpGroup = trim((string)($config['php_group'] ?? ''));
        $action = (string)($argv[1] ?? 'status');

        if ($action === 'status') {
            fwrite(STDOUT, json_encode([
                'ok' => true,
                'version' => 19,
                'capabilities' => [
                    'env_set_many' => true,
                    'db_aws_settings' => true,
                    'web_setup' => true,
                    'bootstrap_complete' => true,
                    'setup_finalize' => true,
                    'managed_replica_settings' => true,
                    'managed_federation_drop_settings' => true,
                    'managed_federation_drop_stripe' => true,
                    'managed_federation_drop_google' => true,
                    'server_console' => true,
                    'memory_drop_caches' => true,
                    'disk_cleanup' => true,
                    'workstation_control' => true,
                    'workstation_document_open' => true,
                    'workstation_document_access_repair' => true,
                    'node_service_control' => true,
                    'local_container_programs' => true,
                ],
                'identity_path' => $identityPath,
                'identity_exists' => is_file($identityPath),
                'runtime_env_path' => $runtimePath,
                'bootstrap_auth_path' => $bootstrapAuthPath,
                'bootstrap_enabled' => is_file($bootstrapAuthPath) && !is_file($setupLockPath),
                'setup_lock_path' => $setupLockPath,
                'setup_locked' => is_file($setupLockPath),
            ], JSON_UNESCAPED_SLASHES) . "\n");
            exit(0);
        }

        if ($action === 'local-container-programs') {
            if (count($argv) !== 2) $this->fail('El inventario local no acepta parámetros.', 64);
            fwrite(STDOUT, json_encode($this->localContainerPrograms(), JSON_THROW_ON_ERROR) . "\n");
            exit(0);
        }

        if ($action === 'bootstrap-init') {
            $this->requireServerOperator($config);
            fwrite(
                STDOUT,
                json_encode(
                    $this->createBootstrapAuth($bootstrapAuthPath, $setupLockPath, $phpGroup),
                    JSON_UNESCAPED_SLASHES
                ) . "\n"
            );
            exit(0);
        }

        if ($action === 'bootstrap-reset') {
            $this->requireServerOperator($config);
            if (is_file($setupLockPath)) {
                $this->fail('La instalación ya está cerrada; no se reactiva bootstrap automáticamente.', 17);
            }
            if (is_file($bootstrapAuthPath) && !unlink($bootstrapAuthPath)) {
                $this->fail('No se pudo reemplazar la credencial bootstrap.');
            }
            fwrite(
                STDOUT,
                json_encode(
                    $this->createBootstrapAuth($bootstrapAuthPath, $setupLockPath, $phpGroup),
                    JSON_UNESCAPED_SLASHES
                ) . "\n"
            );
            exit(0);
        }

        if ($action === 'bootstrap-finalize') {
            if (is_file($setupLockPath)) {
                $this->fail('La instalación ya está cerrada.', 17);
            }
            if (!is_file($bootstrapAuthPath)) {
                $this->fail('El setup bootstrap no está activo; no se puede finalizar desde la web.', 17);
            }

            $this->assertActiveSuperadminExists($runtimePath);

            $appRoot = $this->safeConfiguredPath($config, 'app_root', '/var/www/arcadecloud-drive');
            $phpUser = trim((string)($config['php_user'] ?? ''));
            if ($phpUser === '' || $phpUser === 'root') {
                $this->fail('Usuario PHP-FPM inválido para finalizar la instalación.');
            }

            $summary = $this->finalizeInstallation($appRoot, $phpUser);
            $this->completeBootstrap($bootstrapAuthPath, $setupLockPath);
            fwrite(STDOUT, json_encode([
                'ok' => true,
                'finalized' => true,
                'message' => 'Instalación básica y FederationCloud finalizados.',
                'summary' => $summary,
            ], JSON_UNESCAPED_SLASHES) . "\n");
            exit(0);
        }

        if ($action === 'bootstrap-complete') {
            $this->completeBootstrap($bootstrapAuthPath, $setupLockPath);
            fwrite(STDOUT, "ok\n");
            exit(0);
        }

        if ($action === 'bootstrap-disable') {
            $this->requireServerOperator($config);
            $this->completeBootstrap($bootstrapAuthPath, $setupLockPath);
            fwrite(STDOUT, "ok\n");
            exit(0);
        }

        if ($action === 'env-set') {
            $name = trim((string)($argv[2] ?? ''));
            if (!in_array($name, self::ENV_ALLOWLIST, true)) {
                $this->fail('Variable no permitida.');
            }

            $value = stream_get_contents(STDIN, 8193);
            if (!is_string($value) || strlen($value) > 8192 || str_contains($value, "\0")) {
                $this->fail('Valor inválido o demasiado grande.');
            }
            $value = rtrim($value, "\r\n");

            $current = $this->readRuntimeConfig($runtimePath);
            $current[$name] = $value;
            ksort($current, SORT_STRING);
            $this->writeJsonAtomic($runtimePath, $current, 0640, $phpGroup);
            fwrite(STDOUT, "ok\n");
            exit(0);
        }

        if ($action === 'env-set-many') {
            $payload = stream_get_contents(STDIN, 65537);
            if (!is_string($payload) || strlen($payload) > 65536) {
                $this->fail('Payload administrativo demasiado grande.');
            }

            $decoded = json_decode($payload, true);
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                $this->fail('Payload de variables inválido.');
            }

            $changes = $this->validateEnvironmentMap($decoded);
            $current = $this->readRuntimeConfig($runtimePath);
            foreach ($changes as $name => $value) {
                $current[$name] = $value;
            }
            ksort($current, SORT_STRING);
            $this->writeJsonAtomic($runtimePath, $current, 0640, $phpGroup);
            fwrite(STDOUT, "ok\n");
            exit(0);
        }

        if ($action === 'server-console') {
            $commandId = trim((string)($argv[2] ?? ''));
            $appRoot = $this->safeConfiguredPath($config, 'app_root', '/var/www/arcadecloud-drive');
            $output = $this->runServerConsoleCommand($commandId, $appRoot);
            fwrite(
                STDOUT,
                json_encode([
                    'ok' => true,
                    'command' => $commandId,
                    'output' => $output,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );
            exit(0);
        }

        if ($action === 'node-service') {
            $componentId = trim((string)($argv[2] ?? ''));
            $operation = trim((string)($argv[3] ?? ''));
            $catalog = [
                'federation-sync' => ['arcadecloud-federation-sync.service', ['run-now']],
                'federation-sync-timer' => ['arcadecloud-federation-sync.timer', ['enable', 'disable', 'start', 'stop']],
                'federation-https' => ['arcadecloud-federation-https.service', ['run-now']],
                'federation-https-timer' => ['arcadecloud-federation-https.timer', ['enable', 'disable', 'start', 'stop']],
                'federation-cleanup' => ['arcadecloud-federation-drop-cleanup.service', ['run-now']],
                'federation-cleanup-timer' => ['arcadecloud-federation-drop-cleanup.timer', ['enable', 'disable', 'start', 'stop']],
                'polly-reconcile' => ['arcadecloud-polly-reconcile.service', ['run-now']],
                'polly-reconcile-timer' => ['arcadecloud-polly-reconcile.timer', ['enable', 'disable', 'start', 'stop']],
                'transcribe-reconcile' => ['arcadecloud-transcribe-reconcile.service', ['run-now']],
                'transcribe-reconcile-timer' => ['arcadecloud-transcribe-reconcile.timer', ['enable', 'disable', 'start', 'stop']],
                'media-worker' => ['arcadecloud-media-worker.service', ['start', 'stop', 'restart', 'enable', 'disable']],
                'workstation' => ['arcadecloud-workstation.service', ['start', 'stop', 'restart', 'enable', 'disable']],
            ];
            if (!isset($catalog[$componentId]) || !in_array($operation, $catalog[$componentId][1], true)) {
                $this->fail('Componente o acción no permitidos.', 64);
            }
            [$unit] = $catalog[$componentId];
            if ($operation === 'run-now') {
                $this->runFixedCommand(['/usr/bin/systemctl', 'reset-failed', $unit], [0]);
                $this->runFixedCommand(['/usr/bin/systemctl', 'start', '--no-block', $unit], [0]);
            } elseif ($operation === 'enable') {
                $this->runFixedCommand(['/usr/bin/systemctl', 'enable', '--now', $unit], [0]);
            } elseif ($operation === 'disable') {
                $this->runFixedCommand(['/usr/bin/systemctl', 'disable', '--now', $unit], [0]);
            } else {
                $this->runFixedCommand(['/usr/bin/systemctl', $operation, $unit], [0]);
            }
            fwrite(STDOUT, json_encode(['ok' => true, 'component' => $componentId, 'action' => $operation], JSON_UNESCAPED_SLASHES) . "\n");
            exit(0);
        }

        if (in_array($action, ['workstation-status', 'workstation-start'], true)) {
            $unit = 'arcadecloud-workstation.service';
            $unitPath = '/etc/systemd/system/' . $unit;
            if (!is_file($unitPath)) {
                $this->fail('Workstation no está instalada en este nodo.', 2);
            }

            if ($action === 'workstation-start') {
                $this->runFixedCommand(['/usr/bin/systemctl', 'start', $unit]);
            }

            $activeState = trim($this->runFixedCommand(
                ['/usr/bin/systemctl', 'is-active', $unit],
                [0, 3]
            ));
            $enabledState = trim($this->runFixedCommand(
                ['/usr/bin/systemctl', 'is-enabled', $unit],
                [0, 1]
            ));

            fwrite(
                STDOUT,
                json_encode([
                    'ok' => true,
                    'action' => $action,
                    'service' => $unit,
                    'active' => $activeState === 'active',
                    'state' => $activeState,
                    'enabled' => $enabledState === 'enabled',
                    'enabled_state' => $enabledState,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );
            exit(0);
        }

        if (in_array($action, ['workstation-open-document', 'workstation-repair-document-access'], true)) {
            $relative = trim(str_replace('\\\\', '/', (string)($argv[2] ?? '')));
            if (
                !preg_match('/\\Asessions\\/[a-f0-9]{32}\\/[^\\/\\x00-\\x1F\\x7F]{1,220}\\z/u', $relative)
            ) {
                $this->fail('Ruta de documento Workstation inválida.', 64);
            }

            $workspaceRoot = '/var/lib/arcadecloud-office/phase1-workspace';
            $hostPath = $workspaceRoot . '/' . $relative;
            $realWorkspace = realpath($workspaceRoot);
            $realDocument = realpath($hostPath);
            if (
                $realWorkspace === false
                || $realDocument === false
                || !is_file($realDocument)
                || !str_starts_with($realDocument, rtrim($realWorkspace, '/') . '/')
            ) {
                $this->fail('Documento Workstation no encontrado.', 2);
            }

            $sessionDirectory = dirname($realDocument);
            if (
                !@chgrp($sessionDirectory, 10001)
                || !@chmod($sessionDirectory, 02770)
                || !@chgrp($realDocument, 10001)
                || !@chmod($realDocument, 0660)
            ) {
                $this->fail('No se pudieron normalizar permisos del documento Workstation.', 73);
            }

            $phpUser = trim((string)($config['php_user'] ?? ''));
            if ($phpUser === '' || $phpUser === 'root' || !is_executable('/usr/bin/setfacl')) {
                $this->fail('No se pudo preparar el acceso compartido PHP/LibreOffice al documento.', 73);
            }

            // LibreOffice puede reemplazar el archivo al guardar. Mantener una ACL
            // por defecto evita que PHP-FPM pierda acceso al inode nuevo.
            $this->runFixedCommand([
                '/usr/bin/setfacl',
                '-m',
                'u:' . $phpUser . ':rwx,m::rwx',
                $sessionDirectory,
            ]);
            $this->runFixedCommand([
                '/usr/bin/setfacl',
                '-m',
                'd:u:' . $phpUser . ':rwx,d:m::rwx',
                $sessionDirectory,
            ]);
            $this->runFixedCommand([
                '/usr/bin/setfacl',
                '-m',
                'u:' . $phpUser . ':rw-,m::rw-',
                $realDocument,
            ]);

            clearstatcache(true, $realDocument);
            $documentStat = @stat($realDocument);
            if (
                !is_array($documentStat)
                || (int)($documentStat['gid'] ?? -1) !== 10001
                || (((int)($documentStat['mode'] ?? 0)) & 0777) !== 0660
            ) {
                $this->fail('Permisos del documento Workstation no son seguros para LibreOffice.', 73);
            }

            if ($action === 'workstation-open-document') {
                $this->runFixedCommand([
                    '/usr/bin/docker',
                    'exec',
                    '-d',
                    '--user',
                    '10001',
                    '--env',
                    'DISPLAY=:1',
                    '--env',
                    'HOME=/home/arcade',
                    '--env',
                    'XDG_RUNTIME_DIR=/run/user/10001',
                    'arcadecloud-workstation',
                    'libreoffice',
                    '--nologo',
                    '--norestore',
                    '/workspace/' . $relative,
                ]);
            }

            fwrite(
                STDOUT,
                json_encode([
                    'ok' => true,
                    'action' => $action,
                    'workspace_relative' => $relative,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );
            exit(0);
        }

        if ($action === 'identity-create') {
            $name = $this->normalizeNodeName((string)($argv[2] ?? ''));
            if (is_file($identityPath)) {
                $this->fail('La identidad del nodo ya existe.', 17);
            }

            $pair = sodium_crypto_sign_keypair();
            $public = sodium_crypto_sign_publickey($pair);
            $secret = sodium_crypto_sign_secretkey($pair);
            $data = [
                'version' => 1,
                'node_id' => $this->nodeIdFromPublicKey($public),
                'public_key' => $this->base64UrlEncode($public),
                'secret_key' => $this->base64UrlEncode($secret),
                'payload_key' => $this->base64UrlEncode(
                    random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)
                ),
                'created_at' => gmdate(DATE_ATOM),
                'node_name' => $name,
            ];

            $this->validateIdentity($data);
            $this->writeJsonAtomic($identityPath, $data, 0640, $phpGroup);
            fwrite(
                STDOUT,
                json_encode([
                    'ok' => true,
                    'node_id' => $data['node_id'],
                    'node_name' => $name,
                ]) . "\n"
            );
            exit(0);
        }

        if ($action === 'identity-rename') {
            $name = $this->normalizeNodeName((string)($argv[2] ?? ''));
            if (!is_file($identityPath) || !is_readable($identityPath)) {
                $this->fail('La identidad del nodo no existe o no es legible.', 2);
            }

            $data = json_decode((string)file_get_contents($identityPath), true);
            if (!is_array($data)) {
                $this->fail('La identidad del nodo no contiene JSON válido.');
            }

            $this->validateIdentity($data);
            $beforeId = (string)$data['node_id'];
            $data['node_name'] = $name;
            $this->validateIdentity($data);

            if (!hash_equals($beforeId, (string)$data['node_id'])) {
                $this->fail('El Node ID cambió inesperadamente.');
            }

            $this->writeJsonAtomic($identityPath, $data, 0640, $phpGroup);
            fwrite(STDOUT, "ok\n");
            exit(0);
        }

        $this->fail('Acción no permitida.', 64);
    }

    private function runServerConsoleCommand(string $commandId, string $appRoot): string
    {
        return match ($commandId) {
            'repo-pwd' => $appRoot,
            'repo-list' => $this->runFixedCommand(
                ['/usr/bin/ls', '-lah'],
                [0],
                100,
                $appRoot
            ),
            'repo-status' => $this->runGitCommand(
                $appRoot,
                ['status', '--short', '--branch']
            ),
            'repo-log' => $this->runGitCommand(
                $appRoot,
                ['log', '-10', '--oneline', '--decorate']
            ),
            'repo-size' => $this->runFixedCommand(
                ['/usr/bin/du', '-sh', '.'],
                [0],
                20,
                $appRoot
            ),
            'system-uname' => $this->runFixedCommand(['/usr/bin/uname', '-a']),
            'memory' => $this->runFixedCommand(['/usr/bin/free', '-h']),
            'disk' => $this->runFixedCommand(['/usr/bin/df', '-h', '-x', 'tmpfs', '-x', 'devtmpfs']),
            'uptime' => $this->runFixedCommand(['/usr/bin/uptime']),
            'top-memory' => $this->runFixedCommand(
                ['/usr/bin/ps', '-eo', 'pid,user,%mem,%cpu,rss,comm', '--sort=-rss'],
                [0],
                26
            ),
            'nginx-status' => $this->runFixedCommand(
                ['/usr/bin/systemctl', '--no-pager', '--full', 'status', 'nginx.service'],
                [0, 3]
            ),
            'php-fpm-status' => $this->runFixedCommand(
                ['/usr/bin/systemctl', '--no-pager', '--full', 'status', 'php-fpm-drive.service'],
                [0, 3]
            ),
            'arcadecloud-services' => $this->arcadeCloudServices(),
            'arcadecloud-timers' => $this->arcadeCloudTimers(),
            'media-worker-status' => $this->runFixedCommand(
                ['/usr/bin/systemctl', '--no-pager', '--full', 'status', 'arcadecloud-media-worker.service'],
                [0, 3]
            ),
            'media-tools' => $this->mediaTools(),
            'logs-drive' => $this->tailFixedLog('/var/log/php-fpm-drive/error.log'),
            'logs-nginx' => $this->tailFixedLog('/var/log/nginx/error.log'),
            'logs-federation' => $this->journalUnit('arcadecloud-federation-sync.service'),
            'logs-polly' => $this->journalUnit('arcadecloud-polly-reconcile.service'),
            'logs-transcribe' => $this->journalUnit('arcadecloud-transcribe-reconcile.service'),
            'logs-drop' => $this->journalUnit('arcadecloud-federation-drop-cleanup.service'),
            'logs-media' => $this->journalUnit('arcadecloud-media-worker.service'),
            'memory-clear' => $this->dropLinuxCaches(),
            'disk-clean' => $this->cleanupDiskSafely(),
            default => $this->fail('Comando de terminal no permitido.', 64),
        };
    }

    /** @param array<int,string> $args */
    private function runGitCommand(string $appRoot, array $args): string
    {
        $command = [
            '/usr/bin/git',
            '-c',
            'safe.directory=' . $appRoot,
            '-C',
            $appRoot,
        ];
        foreach ($args as $arg) {
            $command[] = $arg;
        }

        return $this->runFixedCommand($command, [0], 120, $appRoot);
    }

    private function arcadeCloudServices(): string
    {
        $output = $this->runFixedCommand([
            '/usr/bin/systemctl',
            'list-units',
            '--type=service',
            '--all',
            '--no-pager',
            '--plain',
        ]);

        return $this->filterDiagnosticLines(
            $output,
            ['arcadecloud-', 'php-fpm-drive.service', 'nginx.service'],
            'SERVICIOS LOCALES ARCADECLOUD / DRIVE'
        );
    }

    private function arcadeCloudTimers(): string
    {
        $output = $this->runFixedCommand([
            '/usr/bin/systemctl',
            'list-timers',
            '--all',
            '--no-pager',
            '--plain',
        ]);

        return $this->filterDiagnosticLines(
            $output,
            ['arcadecloud-'],
            'TIMERS / TAREAS PROGRAMADAS ARCADECLOUD'
        );
    }

    private function mediaTools(): string
    {
        $lines = ['HERRAMIENTAS MULTIMEDIA LOCALES'];
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            $path = null;
            foreach (['/usr/local/bin', '/usr/bin', '/bin'] as $dir) {
                $candidate = $dir . '/' . $binary;
                if (is_file($candidate) && is_executable($candidate)) {
                    $path = $candidate;
                    break;
                }
            }

            if ($path === null) {
                $lines[] = $binary . ': NO DISPONIBLE';
                continue;
            }

            $version = $this->runFixedCommand([$path, '-version'], [0], 8);
            $firstLine = trim((string)(preg_split('/\R/', $version)[0] ?? ''));
            $lines[] = $binary . ': OK · ' . $path;
            if ($firstLine !== '') {
                $lines[] = '  ' . $firstLine;
            }
        }

        return implode("\n", $lines);
    }

    private function journalUnit(string $unit): string
    {
        $allowedUnits = [
            'arcadecloud-federation-sync.service',
            'arcadecloud-polly-reconcile.service',
            'arcadecloud-transcribe-reconcile.service',
            'arcadecloud-federation-drop-cleanup.service',
            'arcadecloud-media-worker.service',
        ];
        if (!in_array($unit, $allowedUnits, true)) {
            $this->fail('Unidad de journal no permitida.', 64);
        }

        return $this->runFixedCommand([
            '/usr/bin/journalctl',
            '-u',
            $unit,
            '-n',
            '80',
            '--no-pager',
            '-o',
            'short-iso',
        ], [0], 100);
    }

    private function tailFixedLog(string $path): string
    {
        $allowedPaths = [
            '/var/log/php-fpm-drive/error.log',
            '/var/log/nginx/error.log',
        ];
        if (!in_array($path, $allowedPaths, true)) {
            $this->fail('Archivo de log no permitido.', 64);
        }
        if (!is_file($path) || !is_readable($path)) {
            return 'Log no disponible en este servidor: ' . $path;
        }

        return $this->runFixedCommand(
            ['/usr/bin/tail', '-n', '80', $path],
            [0],
            100
        );
    }

    /**
     * @param array<int,string> $needles
     */
    private function filterDiagnosticLines(string $output, array $needles, string $title): string
    {
        $lines = preg_split('/\R/', $output) ?: [];
        $matches = [];

        foreach ($lines as $line) {
            foreach ($needles as $needle) {
                if (str_contains($line, $needle)) {
                    $matches[] = $line;
                    break;
                }
            }
        }

        if ($matches === []) {
            return $title . "\nNo se encontraron unidades instaladas que coincidan.";
        }

        return $title . "\n" . implode("\n", array_slice($matches, 0, 80));
    }

    private function cleanupDiskSafely(): string
    {
        $beforeFree = @disk_free_space('/');
        $now = time();
        $deletedFiles = 0;
        $deletedBytes = 0;

        // Temporales que ArcadeCloud crea directamente en /tmp. Nunca se
        // recorre /tmp completo ni se eliminan colas, uploads o sesiones.
        $tempCutoff = $now - 86400;
        $tmp = rtrim(sys_get_temp_dir(), '/');
        $prefixes = [
            'arcadecloud-share-',
            'arcadecloud-replica-',
            'arcadecloud-drop-ingress-',
            'arcadecloud-multisource-',
            'arcadecloud-range-',
            'arcade-doc-',
            'drive_zip_',
            'drive_s3_',
        ];
        foreach ($prefixes as $prefix) {
            foreach (glob($tmp . '/' . $prefix . '*', GLOB_NOSORT) ?: [] as $path) {
                $this->deleteOldRegularFile($path, $tempCutoff, $deletedFiles, $deletedBytes);
            }
        }

        // Caché de costos: es completamente regenerable. Sólo se retiran
        // entradas antiguas, nunca la carpeta ni enlaces simbólicos.
        $this->cleanupOldFilesInDirectory(
            $tmp . '/arcadecloud-drive-cost-explorer-cache',
            $tempCutoff,
            $deletedFiles,
            $deletedBytes
        );
        $temporaryBytes = $deletedBytes;

        // Logs rotados: conserva siempre los logs activos. Sólo se consideran
        // archivos rotados/archivados con al menos 14 días.
        $logCutoff = $now - (14 * 86400);
        foreach (['/var/log/nginx', '/var/log/php-fpm-drive'] as $logDir) {
            $this->cleanupRotatedLogs($logDir, $logCutoff, $deletedFiles, $deletedBytes);
        }
        $logBytes = max(0, $deletedBytes - $temporaryBytes);

        // journalctl --vacuum sólo elimina journals archivados; nunca el journal
        // activo. Las dos cotas evitan que el journal crezca sin límite.
        $journalMessages = [];
        foreach ([
            ['/usr/bin/journalctl', '--vacuum-time=14d', '--no-pager'],
            ['/usr/bin/journalctl', '--vacuum-size=128M', '--no-pager'],
        ] as $command) {
            try {
                $message = trim($this->runFixedCommand($command, [0], 20));
                if ($message !== '') $journalMessages[] = $message;
            } catch (\Throwable $error) {
                $journalMessages[] = 'Journal no reducido: ' . $error->getMessage();
            }
        }

        $this->runFixedCommand(['/usr/bin/sync']);
        clearstatcache(true, '/');
        $afterFree = @disk_free_space('/');
        $measuredFreed = is_float($beforeFree) && is_float($afterFree)
            ? max(0, (int)round($afterFree - $beforeFree))
            : 0;
        $otherSafeBytes = max(0, $measuredFreed - $deletedBytes);

        return "Limpieza completada\n"
            . "Liberado: " . $this->humanBytes(max($measuredFreed, $deletedBytes)) . ".\n\n"
            . "Temporales: " . $this->humanBytes($temporaryBytes) . ".\n"
            . "Logs: " . $this->humanBytes($logBytes) . ".\n"
            . "Otros seguros: " . $this->humanBytes($otherSafeBytes) . ".\n"
            . "Archivos retirados: {$deletedFiles}.\n"
            . "Tamaño conocido retirado: " . $this->humanBytes($deletedBytes) . ".\n"
            . "Espacio libre recuperado medido: " . $this->humanBytes($measuredFreed) . ".\n"
            . "No se tocaron archivos de usuario, S3, base de datos, uploads activos, colas ni logs actuales."
            . ($journalMessages !== [] ? "\nJournal archivado revisado de forma segura." : '');
    }

    private function deleteOldRegularFile(
        string $path,
        int $cutoff,
        int &$deletedFiles,
        int &$deletedBytes
    ): void {
        if ($path === '' || is_link($path) || !is_file($path)) return;
        $mtime = @filemtime($path);
        if (!is_int($mtime) || $mtime >= $cutoff) return;
        $size = @filesize($path);
        if (@unlink($path)) {
            $deletedFiles++;
            if (is_int($size) && $size > 0) $deletedBytes += $size;
        }
    }

    private function cleanupOldFilesInDirectory(
        string $directory,
        int $cutoff,
        int &$deletedFiles,
        int &$deletedBytes
    ): void {
        if ($directory === '' || is_link($directory) || !is_dir($directory)) return;
        $real = realpath($directory);
        if (!is_string($real) || $real !== $directory) return;

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $directory . '/' . $name;
            if (is_link($path) || is_dir($path)) continue;
            $this->deleteOldRegularFile($path, $cutoff, $deletedFiles, $deletedBytes);
        }
    }

    private function cleanupRotatedLogs(
        string $directory,
        int $cutoff,
        int &$deletedFiles,
        int &$deletedBytes
    ): void {
        if ($directory === '' || is_link($directory) || !is_dir($directory)) return;
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            if (!preg_match('/(?:\\.gz|\\.old|\\.[1-9](?:\\.gz)?|-\\d{8}(?:\\.gz)?|-\\d{4}-\\d{2}-\\d{2}(?:\\.gz)?)\\z/', $name)) {
                continue;
            }
            $this->deleteOldRegularFile(
                $directory . '/' . $name,
                $cutoff,
                $deletedFiles,
                $deletedBytes
            );
        }
    }

    private function humanBytes(int $bytes): string
    {
        $value = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $display = (float)$value;
        while ($display >= 1024 && $index < count($units) - 1) {
            $display /= 1024;
            $index++;
        }
        return number_format($display, $index === 0 ? 0 : 2, '.', '') . ' ' . $units[$index];
    }

    private function dropLinuxCaches(): string
    {
        $before = $this->runFixedCommand(['/usr/bin/free', '-h']);
        $this->runFixedCommand(['/usr/bin/sync']);

        $dropCaches = '/proc/sys/vm/drop_caches';
        if (!is_file($dropCaches) || !is_writable($dropCaches)) {
            $this->fail('El kernel no permite escribir en /proc/sys/vm/drop_caches.', 77);
        }

        $written = file_put_contents($dropCaches, "3\n");
        if ($written === false) {
            $this->fail('No se pudo liberar la caché del kernel.', 74);
        }

        $after = $this->runFixedCommand(['/usr/bin/free', '-h']);
        return "Caché del kernel liberada (page cache, dentries e inodes).\n"
            . "No se terminaron procesos. El servidor puede leer más desde disco temporalmente.\n\n"
            . "ANTES\n" . $before . "\n\nDESPUÉS\n" . $after;
    }

    /** Fixed read-only inventory; the CLI action accepts no target or command. */
    public function localContainerPrograms(?callable $request = null): array
    {
        $request ??= fn(string $method, string $path): array => $this->localDockerRead($method, $path);
        $container = 'arcadecloud-workstation';
        $definitions = [
            'libreoffice' => ['/usr/bin/libreoffice', true],
            'Xtigervnc' => ['/usr/bin/Xtigervnc', true],
            'git' => ['/usr/bin/git', true],
            'python3' => ['/usr/bin/python3', true],
            'aws' => ['/usr/local/bin/aws', true],
            'ffmpeg' => ['/usr/bin/ffmpeg', true],
            'ffprobe' => ['/usr/bin/ffprobe', true],
            'novnc_proxy' => ['/usr/share/novnc/vnc.html', false],
        ];
        $inspection = $request('GET', '/containers/' . $container . '/json');
        $status = (int)($inspection['status'] ?? 0);
        $data = json_decode((string)($inspection['body'] ?? ''), true);
        $state = is_array($data) ? (array)($data['State'] ?? []) : [];
        $known = $status === 200 && isset($state['Running']);
        $running = $known && $state['Running'] === true && empty($state['Paused']) && empty($state['Restarting']) && empty($state['Dead']);
        $programs = [];
        foreach ($definitions as $key => [$path, $executable]) {
            $program = ['container' => $container, 'installed' => false, 'available' => false,
                'state' => $status === 404 ? 'unavailable' : 'unknown'];
            if ($known) {
                $stat = $request('HEAD', '/containers/' . $container . '/archive?path=' . rawurlencode($path));
                $attributes = json_decode((string)base64_decode((string)($stat['stat'] ?? ''), true), true);
                $mode = is_array($attributes) ? (int)($attributes['mode'] ?? 0) : 0;
                $present = ($stat['status'] ?? 0) === 200 && is_array($attributes)
                    && ($mode & 0x80000000) === 0 && (!$executable || ($mode & 0111) !== 0);
                $program['installed'] = $present;
                $program['available'] = $present && $running;
                $program['state'] = $present ? ($running ? 'container' : 'container_stopped')
                    : (($stat['status'] ?? 0) === 404 ? 'unavailable' : 'unknown');
            }
            $programs[$key] = $program;
        }
        return ['ok' => true, 'scope' => 'local', 'programs' => $programs];
    }

    /** Unix socket only, bounded GET/HEAD, no redirects, proxy or Docker environment. */
    private function localDockerRead(string $method, string $path): array
    {
        if (!function_exists('curl_init') || !defined('CURLOPT_UNIX_SOCKET_PATH')) return ['status' => 0];
        $curl = curl_init('http://localhost' . $path);
        if ($curl === false) return ['status' => 0];
        $body = ''; $stat = '';
        curl_setopt_array($curl, [
            CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 200,
            CURLOPT_TIMEOUT_MS => 600,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$stat): int {
                if (stripos($line, 'X-Docker-Container-Path-Stat:') === 0 && strlen($line) < 4096) {
                    $stat = trim(substr($line, strlen('X-Docker-Container-Path-Stat:')));
                }
                return strlen($line);
            },
        ]);
        $ok = curl_exec($curl);
        $status = $ok === false ? 0 : (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        // Body may contain Docker environment. Only the caller extracts State;
        // it must never return/log the raw response or cURL errors.
        return ['status' => $status, 'body' => $body, 'stat' => $stat];
    }

    /**
     * Ejecuta exclusivamente argv compilado por este helper. Nunca recibe una
     * línea de shell desde HTTP y bypass_shell impide interpretar metacaracteres.
     *
     * @param array<int,string> $command
     * @param array<int,int> $allowedExitCodes
     */
    private function runFixedCommand(
        array $command,
        array $allowedExitCodes = [0],
        int $maxLines = 0,
        ?string $cwd = null
    ): string {
        if (!function_exists('proc_open')) {
            $this->fail('proc_open no está disponible para diagnósticos del servidor.', 69);
        }

        $executable = (string)($command[0] ?? '');
        $allowedExecutables = [
            '/usr/bin/free',
            '/usr/bin/df',
            '/usr/bin/uptime',
            '/usr/bin/ps',
            '/usr/bin/systemctl',
            '/usr/bin/sync',
            '/usr/bin/ls',
            '/usr/bin/du',
            '/usr/bin/uname',
            '/usr/bin/git',
            '/usr/bin/journalctl',
            '/usr/bin/tail',
            '/usr/bin/docker',
        ];
        if (!in_array($executable, $allowedExecutables, true) || !is_executable($executable)) {
            $this->fail('Ejecutable de diagnóstico no disponible o no permitido.', 69);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        if ($cwd !== null && (!is_dir($cwd) || $cwd === '' || $cwd[0] !== '/')) {
            $this->fail('Directorio de trabajo de diagnóstico inválido.', 64);
        }

        $process = proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            $this->fail('No se pudo iniciar el diagnóstico del servidor.', 70);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 65537);
        $stderr = stream_get_contents($pipes[2], 8193);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if (!is_string($stdout) || strlen($stdout) > 65536 || !is_string($stderr) || strlen($stderr) > 8192) {
            $this->fail('La salida del diagnóstico fue demasiado grande.', 75);
        }
        if (!in_array($exit, $allowedExitCodes, true)) {
            $message = trim($stderr);
            $this->fail($message !== '' ? $message : 'El diagnóstico terminó con error.', $exit > 0 ? $exit : 1);
        }

        $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
        if ($maxLines > 0 && $output !== '') {
            $lines = preg_split('/\R/', $output) ?: [];
            if (count($lines) > $maxLines) {
                $output = implode("\n", array_slice($lines, 0, $maxLines))
                    . "\n… salida recortada por seguridad";
            }
        }

        return $output;
    }

    private function fail(string $message, int $code = 1): never
    {
        fwrite(STDERR, $message . "\n");
        exit($code);
    }

    private function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function requireServerOperator(array $config): void
    {
        $sudoUser = trim((string)getenv('SUDO_USER'));
        $phpUser = trim((string)($config['php_user'] ?? ''));
        if ($sudoUser !== '' && $phpUser !== '' && hash_equals($phpUser, $sudoUser)) {
            $this->fail(
                'Esta acción bootstrap sólo puede ejecutarla el operador del servidor, no PHP-FPM.',
                77
            );
        }
    }

    private function base64UrlDecode(string $encoded): string
    {
        if ($encoded === '' || !preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded)) {
            $this->fail('Base64URL inválido.');
        }

        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) {
            $this->fail('Base64URL inválido.');
        }

        return $decoded;
    }

    private function nodeIdFromPublicKey(string $public): string
    {
        if (strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $this->fail('Clave pública Ed25519 inválida.');
        }

        return 'acn_' . $this->base64UrlEncode(sodium_crypto_generichash($public, '', 18));
    }

    private function normalizeNodeName(string $name): string
    {
        $name = strtolower(trim($name));
        if (strlen($name) < 3 || strlen($name) > 64
            || !preg_match('/\A[a-z0-9][a-z0-9._-]*[a-z0-9]\z/', $name)) {
            $this->fail('Nombre de nodo inválido.');
        }

        return $name;
    }

    private function readConfig(): array
    {
        if (!is_file(self::CONFIG) || !is_readable(self::CONFIG)) {
            $this->fail('Helper no configurado: falta ' . self::CONFIG . '.');
        }

        $data = json_decode((string)file_get_contents(self::CONFIG), true);
        if (!is_array($data)) {
            $this->fail('Configuración del helper inválida.');
        }

        return $data;
    }

    private function safeConfiguredPath(array $config, string $key, string $default): string
    {
        $path = trim((string)($config[$key] ?? $default));
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '/../')) {
            $this->fail('Ruta configurada inválida para ' . $key . '.');
        }

        return $path;
    }

    private function ensureParent(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            $this->fail('No se pudo crear ' . $dir . '.');
        }
    }

    private function writeJsonAtomic(
        string $path,
        array $data,
        int $mode = 0640,
        ?string $group = null
    ): void {
        $this->ensureParent($path);
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            $this->fail('No se pudo escribir archivo temporal privilegiado.');
        }

        chmod($tmp, $mode);
        if ($group !== null && $group !== '') {
            @chgrp($tmp, $group);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            $this->fail('No se pudo instalar el archivo privilegiado.');
        }

        chmod($path, $mode);
        if ($group !== null && $group !== '') {
            @chgrp($path, $group);
        }
    }

    private function readRuntimeConfig(string $runtimePath): array
    {
        if (!is_file($runtimePath)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($runtimePath), true);
        if ($decoded === []) {
            return [];
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            $this->fail('La configuración administrada existente tiene formato inválido.');
        }

        return $decoded;
    }

    private function validateEnvironmentMap(array $changes): array
    {
        if ($changes === [] || count($changes) > count(self::ENV_ALLOWLIST)) {
            $this->fail('No hay variables válidas para actualizar.');
        }

        $validated = [];
        foreach ($changes as $name => $value) {
            if (!is_string($name) || !in_array($name, self::ENV_ALLOWLIST, true)) {
                $this->fail('Variable no permitida.');
            }
            if (!is_string($value) || strlen($value) > 8192 || str_contains($value, "\0")) {
                $this->fail('Valor inválido o demasiado grande.');
            }
            $validated[$name] = $value;
        }

        return $validated;
    }

    private function createBootstrapAuth(string $authPath, string $lockPath, string $phpGroup): array
    {
        if (is_file($lockPath)) {
            return ['ok' => true, 'created' => false, 'setup_locked' => true];
        }
        if (is_file($authPath)) {
            return [
                'ok' => true,
                'created' => false,
                'setup_locked' => false,
                'bootstrap_exists' => true,
            ];
        }

        $token = bin2hex(random_bytes(32));
        $hash = password_hash('arcadecloud', PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            $this->fail('No se pudo generar el hash del supervisor bootstrap.');
        }

        $this->writeJsonAtomic($authPath, [
            'version' => 1,
            'enabled' => true,
            'username' => 'arcadecloud',
            'password_hash' => $hash,
            'token_hash' => hash('sha256', $token),
            'created_at' => gmdate(DATE_ATOM),
        ], 0640, $phpGroup);

        return [
            'ok' => true,
            'created' => true,
            'setup_locked' => false,
            'username' => 'arcadecloud',
            'initial_password' => 'arcadecloud',
            'activation_token' => $token,
        ];
    }

    private function assertActiveSuperadminExists(string $runtimePath): void
    {
        if (!extension_loaded('mysqli')) {
            $this->fail('PHP mysqli es obligatorio para validar el superadmin antes de finalizar.');
        }

        $runtime = $this->readRuntimeConfig($runtimePath);
        foreach (['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'] as $name) {
            if (!is_string($runtime[$name] ?? null) || trim((string)$runtime[$name]) === '') {
                $this->fail('La configuración de base de datos está incompleta; no se puede finalizar.');
            }
        }

        $portRaw = (string)($runtime['DB_PORT'] ?? '3306');
        $port = filter_var($portRaw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            $this->fail('DB_PORT no es válido; no se puede finalizar.');
        }

        $db = mysqli_init();
        if (!$db) {
            $this->fail('No se pudo inicializar MySQL para validar el superadmin.');
        }
        mysqli_options($db, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        if (!@mysqli_real_connect(
            $db,
            (string)$runtime['DB_HOST'],
            (string)$runtime['DB_USER'],
            (string)$runtime['DB_PASSWORD'],
            (string)$runtime['DB_NAME'],
            (int)$port
        )) {
            $db->close();
            $this->fail('No fue posible validar el superadmin en la base configurada.');
        }

        $result = @$db->query(
            "SELECT id FROM Users WHERE system_role = 'superadmin' AND userstatus = 'Activo' LIMIT 1"
        );
        $found = $result !== false && $result->fetch_assoc() !== null;
        if ($result !== false) {
            $result->free();
        }
        $db->close();

        if (!$found) {
            $this->fail('No existe un superadmin activo; el setup no puede cerrarse.');
        }
    }

    private function finalizeInstallation(string $appRoot, string $phpUser): string
    {
        $installer = rtrim($appRoot, '/') . '/drive/bin/install_arcadecloud.sh';
        if (!is_file($installer) || !is_readable($installer)) {
            $this->fail('No se encontró el instalador ArcadeCloud en el app_root configurado.');
        }

        $tmp = tempnam('/tmp', 'arcadecloud-finalize-');
        if (!is_string($tmp) || $tmp === '') {
            $this->fail('No se pudo crear el log temporal de finalización.');
        }
        chmod($tmp, 0600);

        $command = [
            '/bin/bash',
            $installer,
            '--finalize-from-setup',
            '--app-root=' . $appRoot,
            '--php-user=' . $phpUser,
            '--skip-system-bootstrap',
        ];
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $tmp, 'a'],
            2 => ['file', $tmp, 'a'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            @unlink($tmp);
            $this->fail('No se pudo iniciar la finalización privilegiada.');
        }

        $exit = proc_close($process);
        $size = is_file($tmp) ? (int)filesize($tmp) : 0;
        $offset = max(0, $size - 7000);
        $output = is_file($tmp)
            ? (string)file_get_contents($tmp, false, null, $offset, 7000)
            : '';
        @unlink($tmp);

        if ($exit !== 0) {
            $detail = trim($output);
            $this->fail(
                'La finalización automática falló; el setup permanece abierto para reintentar.'
                . ($detail !== '' ? "\n" . $detail : ''),
                70
            );
        }

        $lines = preg_split('/\R/', trim($output)) ?: [];
        $lines = array_values(array_filter($lines, static fn(string $line): bool => trim($line) !== ''));
        return implode(' | ', array_slice($lines, -4));
    }

    private function completeBootstrap(string $authPath, string $lockPath): void
    {
        $this->writeJsonAtomic($lockPath, [
            'version' => 1,
            'completed' => true,
            'completed_at' => gmdate(DATE_ATOM),
        ], 0644, null);

        if (is_file($authPath)) {
            @unlink($authPath);
        }
    }

    private function validateIdentity(array $data): void
    {
        foreach (['node_id', 'public_key', 'secret_key', 'payload_key'] as $field) {
            if (!is_string($data[$field] ?? null) || $data[$field] === '') {
                $this->fail('Identidad incompleta: ' . $field . '.');
            }
        }

        $public = $this->base64UrlDecode((string)$data['public_key']);
        $secret = $this->base64UrlDecode((string)$data['secret_key']);
        $payload = $this->base64UrlDecode((string)$data['payload_key']);

        if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || strlen($payload) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            $this->fail('Longitudes criptográficas inválidas.');
        }

        if (!hash_equals($this->nodeIdFromPublicKey($public), (string)$data['node_id'])) {
            $this->fail('Node ID no corresponde a la clave pública.');
        }

        if (!hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($secret))) {
            $this->fail('Clave privada y pública no forman el mismo par.');
        }

        if (isset($data['node_name'])) {
            $this->normalizeNodeName((string)$data['node_name']);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    (new ArcadeCloudDriveAdminHelper())->run($argv);
}
