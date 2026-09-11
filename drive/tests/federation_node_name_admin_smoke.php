<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/FederationConfig.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';

use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

$path = sys_get_temp_dir() . '/arcadecloud-node-name-' . bin2hex(random_bytes(6)) . '.json';
$restrictedDir = sys_get_temp_dir() . '/arcadecloud-node-existing-' . bin2hex(random_bytes(6));
$restrictedPath = $restrictedDir . '/federation-node.json';

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

    // Simula /etc: el archivo ya existe y es escribible, pero el directorio no
    // permite crear archivos temporales. El renombre debe actualizar el mismo
    // archivo sin necesitar escritura sobre el directorio.
    if (!mkdir($restrictedDir, 0700, true)) {
        throw new RuntimeException('No se pudo crear directorio temporal restringido.');
    }
    NodeIdentityService::initialize($restrictedPath, false, 'jimmybackend');
    $restrictedService = new NodeIdentityService($restrictedPath);
    $restrictedNodeId = $restrictedService->nodeId();
    $restrictedPublicKey = $restrictedService->publicKeyEncoded();

    if (!chmod($restrictedPath, 0600) || !chmod($restrictedDir, 0500)) {
        throw new RuntimeException('No se pudo preparar la prueba de permisos restringidos.');
    }

    $restrictedRenamed = $restrictedService->renameNodeName('drive.esforzados.com');
    if (($restrictedRenamed['node_name'] ?? null) !== 'drive.esforzados.com') {
        throw new RuntimeException('El archivo existente no se actualizó in-place.');
    }
    if ($restrictedService->nodeId() !== $restrictedNodeId) {
        throw new RuntimeException('El Node ID cambió en la escritura in-place.');
    }
    if ($restrictedService->publicKeyEncoded() !== $restrictedPublicKey) {
        throw new RuntimeException('La clave pública cambió en la escritura in-place.');
    }

    $leftovers = glob($restrictedPath . '.tmp-*');
    if (is_array($leftovers) && $leftovers !== []) {
        throw new RuntimeException('El renombre de archivo existente creó un temporal inesperado.');
    }

    fwrite(STDOUT, "federation node name admin smoke: OK\n");
} finally {
    @chmod($restrictedDir, 0700);
    @chmod($restrictedPath, 0600);
    @unlink($restrictedPath);
    @rmdir($restrictedDir);
    @unlink($path);
}
