<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';

use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

$options = getopt('', ['path:', 'name:', 'force']);
$path = trim((string)($options['path'] ?? getenv('ARCADECLOUD_FEDERATION_IDENTITY') ?: '/etc/arcadecloud-drive/federation-node.json'));
$force = array_key_exists('force', $options);
$name = array_key_exists('name', $options) ? trim((string)$options['name']) : null;

try {
    $identity = NodeIdentityService::initialize($path, $force, $name);
    fwrite(STDOUT, "FederationCloud node identity creada.\n");
    fwrite(STDOUT, 'path=' . $path . "\n");
    fwrite(STDOUT, 'node_id=' . $identity['node_id'] . "\n");
    if (isset($identity['node_name'])) {
        fwrite(STDOUT, 'node_name=' . $identity['node_name'] . "\n");
    }
    fwrite(STDOUT, "La clave privada y payload_key NO se muestran.\n");
    exit(0);
} catch (FederationException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: no se pudo crear la identidad FederationCloud.\n");
    exit(1);
}
