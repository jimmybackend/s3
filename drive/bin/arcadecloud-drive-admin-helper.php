#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Helper privilegiado mínimo de ArcadeCloud Drive.
 * Se instala root:root en /usr/local/sbin/arcadecloud-drive-admin.
 * NUNCA ejecuta comandos arbitrarios ni acepta nombres de variables fuera de allowlist.
 */

const AC_ADMIN_CONFIG = '/etc/arcadecloud-drive/admin-helper.json';
const AC_RUNTIME_ENV_DEFAULT = '/etc/arcadecloud-drive/runtime-env.json';
const AC_IDENTITY_DEFAULT = '/etc/arcadecloud-drive/federation-node.json';

const AC_ENV_ALLOWLIST = [
    'ARCADECLOUD_PUBLIC_URL',
    'ARCADECLOUD_FEDERATION_URL',
    'ARCADECLOUD_FEDERATION_ENABLED',
    'ARCADECLOUD_FEDERATION_SEED_URL',
    'ARCADECLOUD_SMTP_HOST',
    'ARCADECLOUD_SMTP_PORT',
    'ARCADECLOUD_SMTP_SECURE',
    'ARCADECLOUD_SMTP_USERNAME',
    'ARCADECLOUD_SMTP_PASSWORD',
    'ARCADECLOUD_SMTP_FROM_EMAIL',
    'ARCADECLOUD_SMTP_FROM_NAME',
    'ARCADECLOUD_SMTP_REPLY_TO',
    'ARCADECLOUD_SMTP_TIMEOUT',
    'ARCADECLOUD_SMTP_DEBUG',
];

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function isRoot(): bool
{
    return function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('/usr/bin/id -u 2>/dev/null')) === '0';
}

function base64UrlEncode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function base64UrlDecode(string $encoded): string
{
    if ($encoded === '' || !preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded)) fail('Base64URL inválido.');
    $padding = (4 - strlen($encoded) % 4) % 4;
    $decoded = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
    if (!is_string($decoded)) fail('Base64URL inválido.');
    return $decoded;
}

function nodeIdFromPublicKey(string $public): string
{
    if (strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) fail('Clave pública Ed25519 inválida.');
    return 'acn_' . base64UrlEncode(sodium_crypto_generichash($public, '', 18));
}

function normalizeNodeName(string $name): string
{
    $name = strtolower(trim($name));
    if (strlen($name) < 3 || strlen($name) > 64 || !preg_match('/\A[a-z0-9][a-z0-9._-]*[a-z0-9]\z/', $name)) {
        fail('Nombre de nodo inválido.');
    }
    return $name;
}

function readConfig(): array
{
    if (!is_file(AC_ADMIN_CONFIG) || !is_readable(AC_ADMIN_CONFIG)) {
        fail('Helper no configurado: falta ' . AC_ADMIN_CONFIG . '.');
    }
    $data = json_decode((string)file_get_contents(AC_ADMIN_CONFIG), true);
    if (!is_array($data)) fail('Configuración del helper inválida.');
    return $data;
}

function safeConfiguredPath(array $config, string $key, string $default): string
{
    $path = trim((string)($config[$key] ?? $default));
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '/../')) {
        fail('Ruta configurada inválida para ' . $key . '.');
    }
    return $path;
}

function ensureParent(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) fail('No se pudo crear ' . $dir . '.');
}

function writeJsonAtomic(string $path, array $data, int $mode = 0640, ?string $group = null): void
{
    ensureParent($path);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $json, LOCK_EX) === false) fail('No se pudo escribir archivo temporal privilegiado.');
    chmod($tmp, $mode);
    if ($group !== null && $group !== '') @chgrp($tmp, $group);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        fail('No se pudo instalar el archivo privilegiado.');
    }
    chmod($path, $mode);
    if ($group !== null && $group !== '') @chgrp($path, $group);
}

function validateIdentity(array $data): void
{
    foreach (['node_id', 'public_key', 'secret_key', 'payload_key'] as $field) {
        if (!is_string($data[$field] ?? null) || $data[$field] === '') fail('Identidad incompleta: ' . $field . '.');
    }
    $public = base64UrlDecode((string)$data['public_key']);
    $secret = base64UrlDecode((string)$data['secret_key']);
    $payload = base64UrlDecode((string)$data['payload_key']);
    if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || strlen($payload) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
        fail('Longitudes criptográficas inválidas.');
    }
    if (!hash_equals(nodeIdFromPublicKey($public), (string)$data['node_id'])) fail('Node ID no corresponde a la clave pública.');
    $derivedPublic = sodium_crypto_sign_publickey_from_secretkey($secret);
    if (!hash_equals($public, $derivedPublic)) fail('Clave privada y pública no forman el mismo par.');
    if (isset($data['node_name'])) normalizeNodeName((string)$data['node_name']);
}

if (!isRoot()) fail('Este helper debe ejecutarse como root mediante sudo.', 77);
if (!extension_loaded('sodium')) fail('PHP sodium es obligatorio.', 69);

$config = readConfig();
$runtimePath = safeConfiguredPath($config, 'runtime_env_path', AC_RUNTIME_ENV_DEFAULT);
$identityPath = safeConfiguredPath($config, 'identity_path', AC_IDENTITY_DEFAULT);
$phpGroup = trim((string)($config['php_group'] ?? ''));
$action = (string)($argv[1] ?? 'status');

if ($action === 'status') {
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'identity_path' => $identityPath,
        'identity_exists' => is_file($identityPath),
        'runtime_env_path' => $runtimePath,
    ], JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

if ($action === 'env-set') {
    $name = trim((string)($argv[2] ?? ''));
    if (!in_array($name, AC_ENV_ALLOWLIST, true)) fail('Variable no permitida.');
    $value = stream_get_contents(STDIN, 8193);
    if (!is_string($value) || strlen($value) > 8192 || str_contains($value, "\0")) fail('Valor inválido o demasiado grande.');
    $value = rtrim($value, "\r\n");

    $current = [];
    if (is_file($runtimePath)) {
        $decoded = json_decode((string)file_get_contents($runtimePath), true);
        if (is_array($decoded) && !array_is_list($decoded)) $current = $decoded;
    }
    $current[$name] = $value;
    ksort($current, SORT_STRING);
    writeJsonAtomic($runtimePath, $current, 0640, $phpGroup);
    fwrite(STDOUT, "ok\n");
    exit(0);
}

if ($action === 'identity-create') {
    $name = normalizeNodeName((string)($argv[2] ?? ''));
    if (is_file($identityPath)) fail('La identidad del nodo ya existe.', 17);
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $secret = sodium_crypto_sign_secretkey($pair);
    $data = [
        'version' => 1,
        'node_id' => nodeIdFromPublicKey($public),
        'public_key' => base64UrlEncode($public),
        'secret_key' => base64UrlEncode($secret),
        'payload_key' => base64UrlEncode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
        'created_at' => gmdate(DATE_ATOM),
        'node_name' => $name,
    ];
    validateIdentity($data);
    writeJsonAtomic($identityPath, $data, 0640, $phpGroup);
    fwrite(STDOUT, json_encode(['ok' => true, 'node_id' => $data['node_id'], 'node_name' => $name]) . "\n");
    exit(0);
}

if ($action === 'identity-rename') {
    $name = normalizeNodeName((string)($argv[2] ?? ''));
    if (!is_file($identityPath) || !is_readable($identityPath)) fail('La identidad del nodo no existe o no es legible.', 2);
    $data = json_decode((string)file_get_contents($identityPath), true);
    if (!is_array($data)) fail('La identidad del nodo no contiene JSON válido.');
    validateIdentity($data);
    $beforeId = (string)$data['node_id'];
    $data['node_name'] = $name;
    validateIdentity($data);
    if (!hash_equals($beforeId, (string)$data['node_id'])) fail('El Node ID cambió inesperadamente.');
    writeJsonAtomic($identityPath, $data, 0640, $phpGroup);
    fwrite(STDOUT, "ok\n");
    exit(0);
}

if ($action === 'identity-delete-if-id') {
    $expected = trim((string)($argv[2] ?? ''));
    if (!is_file($identityPath)) exit(0);
    $data = json_decode((string)file_get_contents($identityPath), true);
    if (!is_array($data) || !is_string($data['node_id'] ?? null) || !hash_equals((string)$data['node_id'], $expected)) {
        fail('No se eliminó la identidad porque el Node ID no coincide.', 73);
    }
    if (!unlink($identityPath)) fail('No se pudo eliminar la identidad recién creada.');
    fwrite(STDOUT, "ok\n");
    exit(0);
}

fail('Acción no permitida.', 64);
