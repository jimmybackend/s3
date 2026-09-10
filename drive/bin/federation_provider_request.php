<?php
declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    '/src/Federation/FederationException.php',
    '/src/Federation/FederationCodec.php',
    '/src/Federation/FederationConfig.php',
    '/src/Federation/NodeIdentityService.php',
    '/src/Federation/ArcadeLinkService.php',
    '/src/Federation/FederationNodeDescriptorValidator.php',
    '/src/Federation/FederationHttpClient.php',
    '/src/Federation/FederationProviderGrant.php',
] as $file) {
    require_once $root . $file;
}

use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationHttpClient;
use ArcadeCloud\Drive\Federation\FederationNodeDescriptorValidator;
use ArcadeCloud\Drive\Federation\FederationProviderGrant;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

$options = getopt('', [
    'origin:',
    'public-url:',
    'federation-url:',
    'identity::',
    'role::',
    'scope::',
]);

$originUrl = trim((string)($options['origin'] ?? ''));
$publicUrl = trim((string)($options['public-url'] ?? ''));
$federationUrl = trim((string)($options['federation-url'] ?? ''));
$identityPath = trim((string)($options['identity'] ?? '/etc/arcadecloud-drive/federation-node.json'));
$role = (string)($options['role'] ?? 'provider');
$scope = (string)($options['scope'] ?? 'all_allowed_resources');

if ($originUrl === '' || $publicUrl === '' || $federationUrl === '') {
    fwrite(STDERR, "Uso: php drive/bin/federation_provider_request.php --origin=https://origen/federationcloud/ --public-url=https://este-nodo --federation-url=https://este-nodo/federationcloud/ [--identity=/ruta/node.json] [--role=provider] [--scope=all_allowed_resources]\n");
    exit(2);
}

try {
    $role = FederationProviderGrant::normalizeRole($role);
    $scope = FederationProviderGrant::normalizeScope($scope);
    $config = new FederationConfig(
        rtrim($publicUrl, '/'),
        rtrim($federationUrl, '/') . '/',
        $identityPath,
        true
    );
    $identity = new NodeIdentityService($identityPath);
    $validator = new FederationNodeDescriptorValidator();
    $http = new FederationHttpClient();

    $local = $validator->validate($identity->signedDescriptor($config));
    $origin = $validator->validate($http->getJson($originUrl, 'node.php'));
    if (hash_equals((string)$local['node_id'], (string)$origin['node_id'])) {
        throw new FederationException('El nodo origen y este nodo tienen la misma identidad.', 409);
    }

    $response = $http->postJson($originUrl, 'provider-request.php', [
        'origin_node_id' => (string)$origin['node_id'],
        'provider_descriptor' => $local,
        'role' => $role,
        'scope' => $scope,
    ]);

    fwrite(STDOUT, "Solicitud FederationCloud enviada.\n");
    fwrite(STDOUT, 'origin_node_id=' . (string)$origin['node_id'] . "\n");
    fwrite(STDOUT, 'provider_node_id=' . (string)$local['node_id'] . "\n");
    fwrite(STDOUT, 'provider_node_name=' . (string)($local['node_name'] ?? '') . "\n");
    fwrite(STDOUT, 'status=' . (string)($response['status'] ?? 'unknown') . "\n");
    fwrite(STDOUT, 'message=' . (string)($response['message'] ?? '') . "\n");
    exit(0);
} catch (FederationException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "ERROR: no se pudo enviar la solicitud de proveedor FederationCloud.\n");
    exit(1);
}
