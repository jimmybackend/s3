<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityBackupService.php';

use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\NodeIdentityBackupService;

$options = getopt('', ['path:', 'out:', 'passphrase-file:', 'force']);
$path = trim((string)($options['path'] ?? getenv('ARCADECLOUD_FEDERATION_IDENTITY') ?: '/etc/arcadecloud-drive/federation-node.json'));
$out = trim((string)($options['out'] ?? ''));
$passphraseFile = trim((string)($options['passphrase-file'] ?? ''));
$force = array_key_exists('force', $options);

if ($out === '' || $passphraseFile === '') {
    fwrite(STDERR, "ERROR: usa --out=/ruta/respaldo.json --passphrase-file=/ruta/secreta\n");
    exit(1);
}

$passphrase = @file_get_contents($passphraseFile);
if (!is_string($passphrase)) {
    fwrite(STDERR, "ERROR: no se pudo leer passphrase-file.\n");
    exit(1);
}
$passphrase = rtrim($passphrase, "\r\n");

try {
    $result = NodeIdentityBackupService::exportEncrypted($path, $out, $passphrase, $force);
    fwrite(STDOUT, "OK: respaldo cifrado FederationCloud creado.\n");
    fwrite(STDOUT, 'node_id=' . $result['node_id'] . "\n");
    if (is_string($result['node_name'] ?? null)) {
        fwrite(STDOUT, 'node_name=' . $result['node_name'] . "\n");
    }
    fwrite(STDOUT, 'backup_path=' . $result['backup_path'] . "\n");
    fwrite(STDOUT, "La identidad privada no se muestra en pantalla.\n");
    exit(0);
} catch (FederationException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: no se pudo crear el respaldo FederationCloud.\n");
    exit(1);
} finally {
    sodium_memzero($passphrase);
}
