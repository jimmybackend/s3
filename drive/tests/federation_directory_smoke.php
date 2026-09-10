<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Federation/FederationException.php';
require_once $root . '/src/Federation/FederationCodec.php';
require_once $root . '/src/Federation/FederationConfig.php';
require_once $root . '/src/Federation/FederationSeedConfig.php';
require_once $root . '/src/Federation/NodeIdentityService.php';
require_once $root . '/src/Federation/FederationNodeDescriptorValidator.php';

use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationNodeDescriptorValidator;
use ArcadeCloud\Drive\Federation\FederationSeedConfig;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

function directoryOk(bool $condition, string $message): void
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

$tmpDir = sys_get_temp_dir() . '/arcadecloud-directory-test-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);
$identityPath = $tmpDir . '/node.json';

try {
    NodeIdentityService::initialize($identityPath);
    $config = new FederationConfig(
        'https://node.example.test',
        'https://node.example.test/federationcloud/',
        $identityPath,
        true
    );
    $identity = new NodeIdentityService($identityPath);
    $validator = new FederationNodeDescriptorValidator();

    $descriptor = $identity->signedDescriptor($config);
    $valid = $validator->validate($descriptor);
    directoryOk($valid['node_id'] === $identity->nodeId(), 'descriptor firmado conserva Node ID');
    directoryOk($valid['federation_url'] === 'https://node.example.test/federationcloud/', 'descriptor exige Federation URL HTTPS');

    $tampered = $descriptor;
    $tampered['federation_url'] = 'https://otro.example.test/federationcloud/';
    $rejected = false;
    try {
        $validator->validate($tampered);
    } catch (FederationException) {
        $rejected = true;
    }
    directoryOk($rejected, 'descriptor alterado después de firmar es rechazado');

    $seeds = FederationSeedConfig::fromProjectConfig();
    directoryOk($seeds->primary() === 'https://drive.esforzados.com/federationcloud/', 'drive.esforzados.com es el seed inicial');
    directoryOk($seeds->isSeed('https://drive.esforzados.com/federationcloud/'), 'el nodo inicial se reconoce como seed');

    fwrite(STDOUT, "FederationCloud directory smoke test completado.\n");
} finally {
    @unlink($identityPath);
    @rmdir($tmpDir);
}
