<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/FederationConfig.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';

use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

$path = sys_get_temp_dir() . '/arcadecloud-node-name-' . bin2hex(random_bytes(6)) . '.json';

try {
    $initial = NodeIdentityService::initialize($path, false, 'jimmybackend');
    $service = new NodeIdentityService($path);
    $originalNodeId = $service->nodeId();
    $originalPublicKey = $service->publicKeyEncoded();

    $renamed = $service->renameNodeName('drive.esforzados.com');
    if (($renamed['node_name'] ?? null) !== 'drive.esforzados.com') {
        throw new RuntimeException('node_name no se actualizó.');
    }
    if ($service->nodeId() !== $originalNodeId) {
        throw new RuntimeException('El Node ID cambió durante el renombre.');
    }
    if ($service->publicKeyEncoded() !== $originalPublicKey) {
        throw new RuntimeException('La clave pública cambió durante el renombre.');
    }

    $config = new FederationConfig(
        'https://drive.esforzados.com',
        'https://drive.esforzados.com/federationcloud/',
        $path,
        true
    );
    $descriptor = $service->signedDescriptor($config);
    if (($descriptor['node_name'] ?? null) !== 'drive.esforzados.com') {
        throw new RuntimeException('El descriptor firmado no contiene el nombre nuevo.');
    }
    if (($descriptor['node_id'] ?? null) !== $originalNodeId) {
        throw new RuntimeException('El descriptor cambió de Node ID.');
    }

    $ipName = NodeIdentityService::normalizeNodeName('69.6.201.239');
    if ($ipName !== '69.6.201.239') {
        throw new RuntimeException('Una IP pública legible debería ser válida como node_name.');
    }

    fwrite(STDOUT, "federation node name admin smoke: OK\n");
} finally {
    @unlink($path);
}
