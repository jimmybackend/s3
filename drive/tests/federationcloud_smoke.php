<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Federation/FederationException.php';
require_once $root . '/src/Federation/FederationCodec.php';
require_once $root . '/src/Federation/FederationConfig.php';
require_once $root . '/src/Federation/NodeIdentityService.php';
require_once $root . '/src/Federation/ArcadeLinkService.php';

use ArcadeCloud\Drive\Federation\ArcadeLinkService;
use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

function ok(bool $condition, string $message): void
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

$tmpDir = sys_get_temp_dir() . '/arcadecloud-federation-test-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);
$identityPath = $tmpDir . '/node.json';

try {
    $created = NodeIdentityService::initialize($identityPath);
    ok(str_starts_with((string)$created['node_id'], 'acn_'), 'Node ID usa prefijo acn_');

    $config = new FederationConfig(
        'https://drive.example.test',
        'https://drive.example.test/federationcloud/',
        $identityPath,
        true
    );
    $identity = new NodeIdentityService($identityPath);
    $links = new ArcadeLinkService($config, $identity);
    $file = [
        'id_' => 42,
        'Nombre' => 'Documento de prueba.pdf',
        'Tamano' => 123456,
        'AccessType' => 'normal',
    ];
    $sha = 'sha256:' . str_repeat('a', 64);
    $public = $links->create($file, 7, $sha, 'application/pdf', 'PUBLIC', 'unknown_rights');
    ok(str_starts_with((string)$public['resource_id'], 'arl_'), 'Resource ID usa prefijo arl_');
    ok($public['content_id'] === $sha, 'PUBLIC conserva Content ID SHA-256');
    $encoded = $links->encode($public);
    $parsed = $links->parse($encoded);
    ok($parsed['signature']['alg'] === 'Ed25519', 'firma Ed25519 válida');
    $payload = $links->decryptLocalPayload($parsed);
    ok((int)$payload['user_id'] === 7 && (int)$payload['file_id'] === 42, 'payload cifrado se recupera sólo con identidad local');

    $private = $links->create($file, 7, $sha, 'application/pdf', 'PRIVATE', 'link_only');
    ok($private['content_id'] === null, 'PRIVATE nunca publica fingerprint');

    $tampered = $public;
    $tampered['title'] = 'Alterado.pdf';
    $tamperedRejected = false;
    try {
        $links->parse($links->encode($tampered));
    } catch (FederationException) {
        $tamperedRejected = true;
    }
    ok($tamperedRejected, 'alterar metadatos rompe la firma');

    $secure = $file;
    $secure['AccessType'] = 'secure';
    $secureRejected = false;
    try {
        $links->create($secure, 7, $sha, 'application/pdf', 'PUBLIC', 'link_only');
    } catch (FederationException) {
        $secureRejected = true;
    }
    ok($secureRejected, 'archivo secure no puede emitirse PUBLIC');

    fwrite(STDOUT, "FederationCloud smoke test completado.\n");
} finally {
    @unlink($identityPath);
    @rmdir($tmpDir);
}
