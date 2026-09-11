<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';
require_once dirname(__DIR__) . '/src/Federation/FederationEventCodec.php';

use ArcadeCloud\Drive\Federation\FederationEventCodec;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

function fedCatalogOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

$dir = sys_get_temp_dir() . '/arcadecloud-fed-event-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$path = $dir . '/identity.json';
try {
    NodeIdentityService::initialize($path, false, 'esforzados');
    $identity = new NodeIdentityService($path);
    $codec = new FederationEventCodec();
    $payload = [
        'node_id' => $identity->nodeId(),
        'node_name' => 'esforzados',
        'public_url' => 'https://drive.example.test',
        'federation_url' => 'https://drive.example.test/federationcloud/',
    ];
    $issuedAt = gmdate(DATE_ATOM);
    $event = $codec->create($identity, 1, 'node.upsert', $identity->nodeId(), $payload, $issuedAt);
    $verified = $codec->verify($event);
    fedCatalogOk($verified['event_id'] === $event['event_id'], 'firma y Event ID válidos');
    fedCatalogOk($verified['origin_sequence'] === 1, 'conserva secuencia de origen');
    fedCatalogOk($verified['payload']['node_name'] === 'esforzados', 'conserva payload firmado');

    $same = $codec->create($identity, 1, 'node.upsert', $identity->nodeId(), $payload, $issuedAt);
    fedCatalogOk($same['event_id'] === $event['event_id'], 'Event ID determinista para el mismo evento');

    $tampered = $event;
    $tampered['payload']['node_name'] = 'alterado';
    $rejected = false;
    try { $codec->verify($tampered); } catch (FederationException) { $rejected = true; }
    fedCatalogOk($rejected, 'rechaza payload alterado');

    $rejected = false;
    try { $codec->create($identity, 2, 'db.dump', 'x', [], $issuedAt); } catch (FederationException) { $rejected = true; }
    fedCatalogOk($rejected, 'rechaza tipos de evento fuera de allowlist');

    echo "federation catalog event smoke: OK\n";
} finally {
    @unlink($path);
    @rmdir($dir);
}
