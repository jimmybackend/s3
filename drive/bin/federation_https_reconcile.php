#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationEndpointResolver.php';

use ArcadeCloud\Drive\Federation\FederationEndpointResolver;

function failHttps(string $message, int $code = 1): never
{
    fwrite(STDERR, 'ERROR: ' . $message . "\n");
    exit($code);
}

function optionValue(array $argv, string $name, string $default): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (is_string($arg) && str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix));
    }
    return $default;
}

/** @return array{exit_code:int,stdout:string,stderr:string} */
function runFixed(array $command, bool $allowFailure = false): array
{
    if ($command === [] || !is_string($command[0] ?? null) || !str_starts_with($command[0], '/')) {
        throw new RuntimeException('Comando administrativo inválido.');
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar: ' . $command[0]);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], 262145);
    $stderr = stream_get_contents($pipes[2], 262145);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = [
        'exit_code' => $exit,
        'stdout' => is_string($stdout) ? trim($stdout) : '',
        'stderr' => is_string($stderr) ? trim($stderr) : '',
    ];
    if ($exit !== 0 && !$allowFailure) {
        $detail = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
        throw new RuntimeException('Falló ' . basename($command[0]) . ($detail !== '' ? ': ' . $detail : '.'));
    }
    return $result;
}

function readRuntimeJson(string $path): array
{
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    if (!is_string($raw)) throw new RuntimeException('No se pudo leer la configuración runtime.');
    $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if ($decoded === []) return [];
    if (!is_array($decoded) || array_is_list($decoded)) throw new RuntimeException('runtime-env.json no es un objeto JSON válido.');
    $safe = [];
    foreach ($decoded as $key => $value) {
        if (is_string($key) && is_string($value)) $safe[$key] = $value;
    }
    return $safe;
}

function runtimeValue(array $runtime, string $name): string
{
    if (array_key_exists($name, $runtime)) return trim((string)$runtime[$name]);
    $env = getenv($name);
    return is_string($env) ? trim($env) : '';
}

function writeRuntimeJsonInPlace(string $path, array $runtime): void
{
    ksort($runtime, SORT_STRING);
    $json = json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $handle = fopen($path, 'c+');
    if ($handle === false) throw new RuntimeException('No se pudo abrir runtime-env.json para actualizarlo.');
    $locked = false;
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('No se pudo bloquear runtime-env.json.');
        $locked = true;
        if (!rewind($handle) || !ftruncate($handle, 0)) throw new RuntimeException('No se pudo preparar runtime-env.json.');
        $length = strlen($json);
        $written = 0;
        while ($written < $length) {
            $chunk = fwrite($handle, substr($json, $written));
            if ($chunk === false || $chunk === 0) throw new RuntimeException('No se pudo escribir runtime-env.json completamente.');
            $written += $chunk;
        }
        if (!fflush($handle)) throw new RuntimeException('No se pudo confirmar runtime-env.json.');
        if (function_exists('fsync')) @fsync($handle);
    } finally {
        if ($locked) @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function writeAtomicText(string $path, string $content, int $mode = 0644): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear ' . $dir . '.');
    }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) throw new RuntimeException('No se pudo escribir ' . $path . '.');
    chmod($tmp, $mode);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo instalar ' . $path . '.');
    }
    chmod($path, $mode);
}

function detectEc2PublicIpv4(string $curlBin): ?string
{
    if (!is_executable($curlBin)) return null;
    $token = runFixed([
        $curlBin, '-fsS', '--max-time', '2', '-X', 'PUT',
        '-H', 'X-aws-ec2-metadata-token-ttl-seconds: 60',
        'http://169.254.169.254/latest/api/token',
    ], true);
    if ($token['exit_code'] !== 0 || $token['stdout'] === '') return null;

    $ip = runFixed([
        $curlBin, '-fsS', '--max-time', '2',
        '-H', 'X-aws-ec2-metadata-token: ' . $token['stdout'],
        'http://169.254.169.254/latest/meta-data/public-ipv4',
    ], true);
    if ($ip['exit_code'] !== 0) return null;
    $candidate = trim($ip['stdout']);
    return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $candidate : null;
}

function certbotVersionAtLeast54(string $certbotBin): bool
{
    $result = runFixed([$certbotBin, '--version'], true);
    if ($result['exit_code'] !== 0) return false;
    $text = $result['stdout'] . ' ' . $result['stderr'];
    if (!preg_match('/certbot\s+(\d+)\.(\d+)/i', $text, $m)) return false;
    $major = (int)$m[1];
    $minor = (int)$m[2];
    return $major > 5 || ($major === 5 && $minor >= 4);
}

function certbotIdentityArgs(string $email): array
{
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
        return ['--email', $email, '--no-eff-email'];
    }
    return ['--register-unsafely-without-email'];
}

function validateSimpleHost(string $host): string
{
    $host = strtolower(trim($host));
    if ($host === '' || strlen($host) > 253 || !preg_match('/\A[a-z0-9._-]+\z/', $host)) {
        throw new RuntimeException('backend-host inválido.');
    }
    return $host;
}

function nginxChallengeConfig(string $host, string $webroot): string
{
    return "# Managed by ArcadeCloud FederationCloud HTTPS.\n"
        . "server {\n"
        . "    listen 80;\n"
        . "    server_name {$host};\n"
        . "    root {$webroot};\n"
        . '    location ^~ /.well-known/acme-challenge/ { try_files $uri =404; }' . "\n"
        . "    location / { return 404; }\n"
        . "}\n";
}

function nginxDynamicIpConfig(string $ip, string $webroot, string $backendHost): string
{
    $certDir = '/etc/letsencrypt/live/' . $ip;
    return "# Managed by ArcadeCloud FederationCloud HTTPS. Do not edit manually.\n"
        . "server {\n"
        . "    listen 80;\n"
        . "    server_name {$ip};\n"
        . "    root {$webroot};\n"
        . '    location ^~ /.well-known/acme-challenge/ { try_files $uri =404; }' . "\n"
        . '    location / { return 301 https://$host$request_uri; }' . "\n"
        . "}\n\n"
        . "server {\n"
        . "    listen 443 ssl;\n"
        . "    server_name {$ip};\n"
        . "    ssl_certificate {$certDir}/fullchain.pem;\n"
        . "    ssl_certificate_key {$certDir}/privkey.pem;\n"
        . "    ssl_session_cache shared:ArcadeCloudTLS:10m;\n"
        . "    ssl_session_timeout 10m;\n"
        . "    location / {\n"
        . "        proxy_pass http://127.0.0.1:80;\n"
        . "        proxy_http_version 1.1;\n"
        . "        proxy_set_header Host {$backendHost};\n"
        . '        proxy_set_header X-Real-IP $remote_addr;' . "\n"
        . '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;' . "\n"
        . "        proxy_set_header X-Forwarded-Proto https;\n"
        . '        proxy_set_header X-Forwarded-Host $host;' . "\n"
        . "    }\n"
        . "}\n";
}

function nginxReload(string $nginxBin, string $systemctlBin): void
{
    runFixed([$nginxBin, '-t']);
    runFixed([$systemctlBin, 'reload', 'nginx']);
}

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    failHttps('federation_https_reconcile.php debe ejecutarse como root.', 77);
}

try {
    $runtimePath = optionValue($argv, 'runtime-env', '/etc/arcadecloud-drive/runtime-env.json');
    $webroot = optionValue($argv, 'webroot', dirname(__DIR__));
    $nginxIpConfig = optionValue($argv, 'nginx-ip-config', '/etc/nginx/conf.d/arcadecloud-federation-ip.conf');
    $statePath = optionValue($argv, 'state-path', '/var/lib/arcadecloud-drive/federation-https-state.json');
    $backendHost = validateSimpleHost(optionValue($argv, 'backend-host', 'localhost'));
    $runUser = validateSimpleHost(optionValue($argv, 'run-user', 'nginx'));
    $appRoot = optionValue($argv, 'app-root', dirname(__DIR__, 2));
    $phpBin = optionValue($argv, 'php-bin', '/usr/bin/php');
    $certbotBin = optionValue($argv, 'certbot-bin', '/usr/bin/certbot');
    $nginxBin = optionValue($argv, 'nginx-bin', '/usr/sbin/nginx');
    $systemctlBin = optionValue($argv, 'systemctl-bin', '/usr/bin/systemctl');
    $curlBin = optionValue($argv, 'curl-bin', '/usr/bin/curl');
    $runuserBin = optionValue($argv, 'runuser-bin', '/usr/sbin/runuser');

    foreach ([$runtimePath, $webroot, $nginxIpConfig, $statePath, $appRoot, $phpBin, $certbotBin, $nginxBin, $systemctlBin, $curlBin, $runuserBin] as $path) {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new RuntimeException('Todas las rutas del reconciliador deben ser absolutas.');
        }
    }
    if (!is_dir($webroot) || preg_match('/[\s;]/', $webroot)) throw new RuntimeException('webroot inválido.');
    if (!is_executable($phpBin) || !is_executable($certbotBin) || !is_executable($nginxBin)
        || !is_executable($systemctlBin) || !is_executable($curlBin) || !is_executable($runuserBin)) {
        throw new RuntimeException('Falta PHP, Certbot, Nginx, systemctl, curl o runuser en las rutas configuradas.');
    }
    if (!function_exists('posix_getpwnam') || posix_getpwnam($runUser) === false) {
        throw new RuntimeException('El usuario configurado para refrescar FederationCloud no existe.');
    }

    $runtime = readRuntimeJson($runtimePath);
    $publicUrl = runtimeValue($runtime, 'ARCADECLOUD_PUBLIC_URL');
    $federationUrl = runtimeValue($runtime, 'ARCADECLOUD_FEDERATION_URL');
    $email = runtimeValue($runtime, 'ARCADECLOUD_SMTP_FROM_EMAIL');
    $detectedIp = detectEc2PublicIpv4($curlBin);

    $endpoint = (new FederationEndpointResolver())->resolve($publicUrl, $federationUrl, $detectedIp);
    $mode = (string)$endpoint['mode'];
    $host = (string)$endpoint['host'];
    $identityArgs = certbotIdentityArgs($email);

    if ($mode === 'domain') {
        $command = array_merge([
            $certbotBin, 'run', '--non-interactive', '--agree-tos', '--no-redirect',
            '--nginx', '--cert-name', $host, '-d', $host,
        ], $identityArgs);
        runFixed($command);

        if (is_file($nginxIpConfig)) {
            $old = file_get_contents($nginxIpConfig);
            if (!is_string($old)) throw new RuntimeException('No se pudo leer la configuración IP anterior.');
            unlink($nginxIpConfig);
            try {
                nginxReload($nginxBin, $systemctlBin);
            } catch (\Throwable $e) {
                writeAtomicText($nginxIpConfig, $old);
                nginxReload($nginxBin, $systemctlBin);
                throw $e;
            }
        } else {
            nginxReload($nginxBin, $systemctlBin);
        }
        $certDir = '/etc/letsencrypt/live/' . $host;
    } else {
        if (!certbotVersionAtLeast54($certbotBin)) {
            throw new RuntimeException('Los certificados IP requieren Certbot 5.4 o superior.');
        }

        $certDir = '/etc/letsencrypt/live/' . $host;
        $hasCurrentCertificate = is_file($certDir . '/fullchain.pem') && is_file($certDir . '/privkey.pem');
        if (!$hasCurrentCertificate) {
            writeAtomicText($nginxIpConfig, nginxChallengeConfig($host, $webroot));
            nginxReload($nginxBin, $systemctlBin);
        }

        $command = array_merge([
            $certbotBin, 'certonly', '--non-interactive', '--agree-tos',
            '--preferred-profile', 'shortlived', '--webroot', '--webroot-path', $webroot,
            '--ip-address', $host, '--cert-name', $host,
            '--deploy-hook', $systemctlBin . ' reload nginx',
        ], $identityArgs);
        runFixed($command);

        if (!is_file($certDir . '/fullchain.pem') || !is_file($certDir . '/privkey.pem')) {
            throw new RuntimeException('Certbot terminó sin dejar el certificado IP esperado.');
        }

        writeAtomicText($nginxIpConfig, nginxDynamicIpConfig($host, $webroot, $backendHost));
        nginxReload($nginxBin, $systemctlBin);
    }

    $runtime['ARCADECLOUD_PUBLIC_URL'] = (string)$endpoint['public_url'];
    $runtime['ARCADECLOUD_FEDERATION_URL'] = (string)$endpoint['federation_url'];
    writeRuntimeJsonInPlace($runtimePath, $runtime);

    $state = [
        'version' => 1,
        'mode' => $mode,
        'host' => $host,
        'public_url' => (string)$endpoint['public_url'],
        'federation_url' => (string)$endpoint['federation_url'],
        'detected_public_ipv4' => $detectedIp,
        'ip_changed' => (bool)$endpoint['ip_changed'],
        'certificate_fullchain' => $certDir . '/fullchain.pem',
        'updated_at' => gmdate(DATE_ATOM),
    ];
    writeAtomicText($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0644);

    $refresh = [$runuserBin, '-u', $runUser, '--', $phpBin, $appRoot . '/drive/bin/federation_endpoint_refresh.php'];
    $registration = runFixed($refresh, true);
    if ($registration['stdout'] !== '') fwrite(STDOUT, $registration['stdout'] . "\n");
    if ($registration['exit_code'] !== 0) {
        fwrite(STDERR, "WARN: HTTPS quedó listo, pero el descriptor se reintentará después: {$registration['stderr']}\n");
    }

    fwrite(STDOUT, "OK: FederationCloud HTTPS reconciliado.\n");
    fwrite(STDOUT, 'MODE=' . $mode . "\n");
    fwrite(STDOUT, 'PUBLIC_URL=' . $endpoint['public_url'] . "\n");
    fwrite(STDOUT, 'FEDERATION_URL=' . $endpoint['federation_url'] . "\n");
} catch (\Throwable $e) {
    failHttps($e->getMessage());
}
