#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Federation/FederationException.php';
require_once $root . '/src/Federation/FederationEndpointPlan.php';

use ArcadeCloud\Drive\Federation\FederationEndpointPlan;
use ArcadeCloud\Drive\Federation\FederationException;

function option(array $argv, string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (is_string($arg) && str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix));
    }
    return $default;
}

function ec2PublicIpv4(): ?string
{
    $base = 'http://169.254.169.254/latest/';
    if (function_exists('curl_init')) {
        $tokenHandle = curl_init($base . 'api/token');
        if ($tokenHandle !== false) {
            curl_setopt_array($tokenHandle, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => ['X-aws-ec2-metadata-token-ttl-seconds: 60'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 700,
                CURLOPT_TIMEOUT_MS => 1200,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $token = curl_exec($tokenHandle);
            $tokenCode = (int)curl_getinfo($tokenHandle, CURLINFO_RESPONSE_CODE);
            curl_close($tokenHandle);
            if (is_string($token) && $token !== '' && $tokenCode === 200) {
                $ipHandle = curl_init($base . 'meta-data/public-ipv4');
                if ($ipHandle !== false) {
                    curl_setopt_array($ipHandle, [
                        CURLOPT_HTTPHEADER => ['X-aws-ec2-metadata-token: ' . trim($token)],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CONNECTTIMEOUT_MS => 700,
                        CURLOPT_TIMEOUT_MS => 1200,
                        CURLOPT_FOLLOWLOCATION => false,
                    ]);
                    $ip = curl_exec($ipHandle);
                    $ipCode = (int)curl_getinfo($ipHandle, CURLINFO_RESPONSE_CODE);
                    curl_close($ipHandle);
                    if (is_string($ip) && $ipCode === 200) return trim($ip);
                }
            }
        }
    }

    $tokenContext = stream_context_create(['http' => [
        'method' => 'PUT',
        'header' => "X-aws-ec2-metadata-token-ttl-seconds: 60\r\n",
        'timeout' => 1.2,
        'ignore_errors' => true,
    ]]);
    $token = @file_get_contents($base . 'api/token', false, $tokenContext);
    if (!is_string($token) || trim($token) === '') return null;

    $ipContext = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => 'X-aws-ec2-metadata-token: ' . trim($token) . "\r\n",
        'timeout' => 1.2,
        'ignore_errors' => true,
    ]]);
    $ip = @file_get_contents($base . 'meta-data/public-ipv4', false, $ipContext);
    return is_string($ip) && trim($ip) !== '' ? trim($ip) : null;
}

$configPath = option($argv, 'config', '/etc/arcadecloud-drive/federation-endpoint.json');
$overrideIp = trim(option($argv, 'ip'));

try {
    if ($configPath === '' || $configPath[0] !== '/' || !is_file($configPath) || !is_readable($configPath)) {
        throw new FederationException('No se puede leer la configuración del endpoint FederationCloud: ' . $configPath, 500);
    }
    $config = json_decode((string)file_get_contents($configPath), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($config) || array_is_list($config)) {
        throw new FederationException('La configuración del endpoint FederationCloud es inválida.', 500);
    }

    $mode = strtolower(trim((string)($config['mode'] ?? 'auto')));
    $domain = trim((string)($config['domain'] ?? ''));
    $detectedIp = $overrideIp;
    if ($detectedIp === '' && ($mode === 'ip' || ($mode === 'auto' && $domain === ''))) {
        $detectedIp = (string)(ec2PublicIpv4() ?? '');
    }

    $plan = (new FederationEndpointPlan())->resolve($config, $detectedIp !== '' ? $detectedIp : null);
    $plan['environment'] = [
        'ARCADECLOUD_PUBLIC_URL' => $plan['public_url'],
        'ARCADECLOUD_FEDERATION_URL' => $plan['federation_url'],
    ];
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Federation endpoint plan error: ' . $e->getMessage() . "\n");
    exit(1);
}
