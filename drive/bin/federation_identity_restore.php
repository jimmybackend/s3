<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityBackupService.php';

use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\NodeIdentityBackupService;

$options = getopt('', ['backup:', 'path:', 'passphrase-file:', 'force']);
$backup = trim((string)($options['backup'] ?? ''));
$path = trim((string)($options['path'] ?? getenv('ARCADECLOUD_FEDERATION_IDENTITY') ?: '/etc/arcadecloud-drive/federation-node.json'));
$passphraseFile = trim((string)($options['passphrase-file'] ?? ''));
$force = array_key_exists('force', $options);

if ($backup === '' || $passphraseFile === '') {
    fwrite(STDERR, "ERROR: usa --backup=/ruta/respaldo.json --passphrase-file=/ruta/secreta\n");
    exit(1);
}

$passphrase = @file_get_contents($passphraseFile);
if (!is_string($passphrase)) {
    fwrite(STDERR, "ERROR: no se pudo leer passphrase-file.\n");
    exit(1);
}
$passphrase = rtrim($passphrase, "\r\n");

try {
    $result = NodeIdentityBackupService::restoreEncrypted($backup, $path, $passphrase, $force);
    fwrite(STDOUT, "OK: identidad FederationCloud restaurada.\n");
    fwrite(STDOUT, 'node_id=' . $result['node_id'] . "\n");
    if (is_string($result['node_name'] ?? null)) {
        fwrite(STDOUT, 'node_name=' . $result['node_name'] . "\n");
    }
    fwrite(STDOUT, 'identity_path=' . $result['identity_path'] . "\n");
    exit(0);
} catch (FederationException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: no se pudo restaurar la identidad FederationCloud.\n");
    exit(1);
} finally {
    sodium_memzero($passphrase);
}
