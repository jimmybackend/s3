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
                'version' => 8,
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

(new ArcadeCloudDriveAdminHelper())->run($argv);
