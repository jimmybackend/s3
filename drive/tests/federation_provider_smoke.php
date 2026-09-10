<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Federation/FederationException.php';
require_once $root . '/src/Federation/FederationCodec.php';
require_once $root . '/src/Federation/NodeIdentityService.php';
require_once $root . '/src/Federation/FederationProviderGrant.php';

use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationProviderGrant;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

function providerOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "FAIL: sodium no está disponible\n");
    exit(1);
}

$tmpDir = sys_get_temp_dir() . '/arcadecloud-provider-test-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);
$originPath = $tmpDir . '/origin.json';
$providerPath = $tmpDir . '/provider.json';

try {
    NodeIdentityService::initialize($originPath, false, 'origin-test');
    NodeIdentityService::initialize($providerPath, false, 'provider-test');
    $origin = new NodeIdentityService($originPath);
    $provider = new NodeIdentityService($providerPath);

    $grant = FederationProviderGrant::sign(
        $origin,
        $provider->nodeId(),
        'provider',
        'all_allowed_resources'
    );
    providerOk(
        FederationProviderGrant::verify($grant, $origin->publicKeyEncoded()),
        'el nodo origen firma una autorización verificable'
    );
    providerOk(
        $grant['origin_node_id'] === $origin->nodeId()
            && $grant['provider_node_id'] === $provider->nodeId(),
        'la autorización liga origen y proveedor por Node ID'
    );

    $tampered = $grant;
    $tampered['scope'] = 'selected_resources';
    providerOk(
        !FederationProviderGrant::verify($tampered, $origin->publicKeyEncoded()),
        'alterar el alcance invalida la autorización firmada'
    );

    $invalidRoleRejected = false;
    try {
        FederationProviderGrant::payload($origin->nodeId(), $provider->nodeId(), 'owner', 'all_allowed_resources');
    } catch (FederationException) {
        $invalidRoleRejected = true;
    }
    providerOk($invalidRoleRejected, 'roles de proveedor no soportados son rechazados');

    fwrite(STDOUT, "FederationCloud provider smoke test completado.\n");
} finally {
    @unlink($originPath);
    @unlink($providerPath);
    @rmdir($tmpDir);
}
