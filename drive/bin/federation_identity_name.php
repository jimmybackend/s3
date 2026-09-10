<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';

use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

$options = getopt('', ['path:', 'name:']);
$path = trim((string)($options['path'] ?? getenv('ARCADECLOUD_FEDERATION_IDENTITY') ?: '/etc/arcadecloud-drive/federation-node.json'));
$name = trim((string)($options['name'] ?? ''));

if ($name === '') {
    fwrite(STDERR, "ERROR: usa --name=nombre-del-nodo\n");
    exit(1);
}

try {
    $identity = new NodeIdentityService($path);
    $data = $identity->assignNodeName($name);
    fwrite(STDOUT, "OK: nombre FederationCloud guardado dentro de la identidad.\n");
    fwrite(STDOUT, 'path=' . $path . "\n");
    fwrite(STDOUT, 'node_id=' . $data['node_id'] . "\n");
    fwrite(STDOUT, 'node_name=' . $data['node_name'] . "\n");
    exit(0);
} catch (FederationException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: no se pudo asignar el nombre FederationCloud.\n");
    exit(1);
}
