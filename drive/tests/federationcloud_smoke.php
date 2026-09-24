<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Federation/FederationException.php';
require_once $root . '/src/Federation/FederationCodec.php';
require_once $root . '/src/Federation/FederationConfig.php';
require_once $root . '/src/Federation/NodeIdentityService.php';
require_once $root . '/src/Federation/ArcadeLinkService.php';

use ArcadeCloud\Drive\Federation\ArcadeLinkService;
use ArcadeCloud\Drive\Federation\FederationCodec;
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

function legacyDocument(ArcadeLinkService $links, NodeIdentityService $identity, FederationConfig $config): array
{
    $resourceId = $links->resourceId(7, 42);
    $document = [
        'format' => 'arcadelink',
        'version' => 1,
        'resource_id' => $resourceId,
        'origin_node_id' => $identity->nodeId(),
        'origin' => $config->publicUrl(),
        'federation_url' => $config->federationUrl(),
        'resource_type' => 'file',
        'title' => 'Documento legado.pdf',
        'size_bytes' => 123456,
        'media_type' => 'application/pdf',
        'visibility' => 'PUBLIC',
        'rights' => 'unknown_rights',
        'content_id' => 'sha256:' . str_repeat('a', 64),
        'issued_at' => gmdate(DATE_ATOM),
    ];
    $privatePayload = [
        'version' => 1,
        'user_id' => 7,
        'file_id' => 42,
        'resource_id' => $resourceId,
    ];
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
        FederationCodec::canonicalJson($privatePayload),
        FederationCodec::canonicalJson($document),
        $nonce,
        $identity->payloadKey()
    );
    $document['payload'] = [
        'alg' => 'XChaCha20-Poly1305',
        'nonce' => FederationCodec::base64UrlEncode($nonce),
        'ciphertext' => FederationCodec::base64UrlEncode($ciphertext),
    ];
    $signed = FederationCodec::canonicalJson($document);
    $document['signature'] = [
        'alg' => 'Ed25519',
        'key_id' => $identity->nodeId(),
        'public_key' => $identity->publicKeyEncoded(),
        'value' => FederationCodec::base64UrlEncode($identity->sign($signed)),
    ];
    return $document;
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
        'Encriptado' => 'stable-object-42.bin',
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
    ok((int)$payload['version'] === 2 && $payload['storage_ref'] === 'stable-object-42.bin', 'ArcadeLink nuevo conserva referencia estable cifrada');
    ok($public['resource_id'] === $links->resourceIdForStorageRef(7, 'stable-object-42.bin'), 'Resource ID nuevo depende de referencia estable');

    $secondFile = [
        'id_' => 43,
        'Nombre' => 'Segundo documento.txt',
        'Encriptado' => 'stable-object-43.bin',
        'Tamano' => 321,
        'AccessType' => 'normal',
    ];
    $second = $links->create($secondFile, 7, 'sha256:' . str_repeat('b', 64), 'text/plain', 'PUBLIC', 'link_only');
    $collection = $links->createCollection([$public, $second], 'Pruebas compartidas');
    ok((int)$collection['version'] === 2, 'colección usa ArcadeLink v2');
    ok($collection['resource_type'] === 'collection', 'colección declara resource_type collection');
    ok((int)$collection['item_count'] === 2 && count($collection['items']) === 2, 'un ArcadeLink contiene varios recursos');
    $parsedCollection = $links->parse($links->encode($collection));
    ok((int)$parsedCollection['item_count'] === 2, 'lector valida colección completa');
    ok(
        $parsedCollection['resource_id'] === $links->collectionResourceId([$public['resource_id'], $second['resource_id']]),
        'Resource ID de colección es portable y determinista'
    );

    $tamperedCollection = $collection;
    $tamperedCollection['items'][1]['title'] = 'Alterado dentro de colección.txt';
    $tamperedCollectionRejected = false;
    try {
        $links->parse($links->encode($tamperedCollection));
    } catch (FederationException) {
        $tamperedCollectionRejected = true;
    }
    ok($tamperedCollectionRejected, 'alterar un recurso interno invalida la colección');

    $drop = $links->createDrop([
        'drop_id' => 'fdp_' . FederationCodec::base64UrlEncode(random_bytes(18)),
        'title' => 'Entrega temporal.bin',
        'size_bytes' => 9876,
        'media_type' => 'application/octet-stream',
        'download_url' => 'https://drive.example.test/federationdrop/d.php?id=fdp_test&t=synthetic',
        'expires_at' => gmdate(DATE_ATOM, time() + 3600),
    ]);
    ok((int)$drop['version'] === 3 && $drop['resource_type'] === 'drop', 'FederationDrop usa ArcadeLink v3');
    ok($drop['visibility'] === 'UNLISTED' && $drop['rights'] === 'copy_allowed', 'FederationDrop conserva política portable esperada');
    $parsedDrop = $links->parse($links->encode($drop));
    ok($parsedDrop['signature']['alg'] === 'Ed25519', 'ArcadeLink FederationDrop conserva firma Ed25519');
    $tamperedDrop = $drop;
    $tamperedDrop['download_url'] = 'https://attacker.example.test/file';
    $tamperedDropRejected = false;
    try {
        $links->parse($links->encode($tamperedDrop));
    } catch (FederationException) {
        $tamperedDropRejected = true;
    }
    ok($tamperedDropRejected, 'alterar URL FederationDrop rompe la firma');

    $legacy = legacyDocument($links, $identity, $config);
    $legacyParsed = $links->parse($links->encode($legacy));
    $legacyPayload = $links->decryptLocalPayload($legacyParsed);
    ok((int)$legacyPayload['version'] === 1 && (int)$legacyPayload['file_id'] === 42, 'ArcadeLink legado version 1 sigue siendo compatible');

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
