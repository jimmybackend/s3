<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Federation/FederationException.php';
require_once $root . '/src/Federation/FederationCodec.php';
require_once $root . '/src/Federation/FederationConfig.php';
require_once $root . '/src/Federation/FederationSeedConfig.php';
require_once $root . '/src/Federation/NodeIdentityService.php';
require_once $root . '/src/Federation/NodeIdentityBackupService.php';
require_once $root . '/src/Federation/FederationNodeDescriptorValidator.php';

use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationNodeDescriptorValidator;
use ArcadeCloud\Drive\Federation\FederationSeedConfig;
use ArcadeCloud\Drive\Federation\NodeIdentityBackupService;
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
$backupPath = $tmpDir . '/node-backup.json';
$restoredPath = $tmpDir . '/node-restored.json';

try {
    NodeIdentityService::initialize($identityPath, false, 'jimmybackend');
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
    directoryOk($valid['node_name'] === 'jimmybackend', 'descriptor firmado conserva nombre público del nodo');
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

    $tamperedName = $descriptor;
    $tamperedName['node_name'] = 'otro-nodo';
    $nameRejected = false;
    try {
        $validator->validate($tamperedName);
    } catch (FederationException) {
        $nameRejected = true;
    }
    directoryOk($nameRejected, 'nombre alterado después de firmar es rechazado');

    $renameRejected = false;
    try {
        $identity->assignNodeName('otro-nodo');
    } catch (FederationException) {
        $renameRejected = true;
    }
    directoryOk($renameRejected, 'una identidad nombrada no se rebautiza silenciosamente');

    $passphrase = 'recovery-test-passphrase-2026';
    NodeIdentityBackupService::exportEncrypted($identityPath, $backupPath, $passphrase);
    $restored = NodeIdentityBackupService::restoreEncrypted($backupPath, $restoredPath, $passphrase);
    directoryOk($restored['node_id'] === $identity->nodeId(), 'respaldo cifrado restaura el mismo Node ID');
    directoryOk($restored['node_name'] === 'jimmybackend', 'respaldo cifrado restaura el mismo nombre');

    $seeds = FederationSeedConfig::fromProjectConfig();
    directoryOk($seeds->primary() === 'https://drive.esforzados.com/federationcloud/', 'drive.esforzados.com es el seed inicial');
    directoryOk($seeds->isSeed('https://drive.esforzados.com/federationcloud/'), 'el nodo inicial se reconoce como seed');

    fwrite(STDOUT, "FederationCloud directory smoke test completado.\n");
} finally {
    @unlink($identityPath);
    @unlink($backupPath);
    @unlink($restoredPath);
    @rmdir($tmpDir);
}
