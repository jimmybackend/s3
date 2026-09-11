<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';
require_once dirname(__DIR__) . '/src/Federation/FederationProviderGrant.php';
require_once dirname(__DIR__) . '/src/Federation/FederationReplicaMessageCodec.php';
require_once dirname(__DIR__) . '/src/Federation/FederationLocationSelector.php';

use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationLocationSelector;
use ArcadeCloud\Drive\Federation\FederationProviderGrant;
use ArcadeCloud\Drive\Federation\FederationReplicaMessageCodec;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

function replicaOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

$dir = sys_get_temp_dir() . '/arcadecloud-replica-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$originPath = $dir . '/origin.json';
$providerPath = $dir . '/provider.json';
try {
    NodeIdentityService::initialize($originPath, false, 'esforzados');
    NodeIdentityService::initialize($providerPath, false, 'mirror-node');
    $origin = new NodeIdentityService($originPath);
    $provider = new NodeIdentityService($providerPath);

    $grant = FederationProviderGrant::sign(
        $origin,
        $provider->nodeId(),
        'mirror',
        'all_allowed_resources'
    );
    $codec = new FederationReplicaMessageCodec();
    $offer = $codec->createOffer(
        $origin,
        $provider->nodeId(),
        'mirror',
        'arl_' . str_repeat('R', 24),
        'https://synthetic-bucket.s3.us-east-1.amazonaws.com/object?X-Amz-Signature=synthetic',
        'sha256:' . str_repeat('a', 64),
        1234,
        'documento.pdf',
        'application/pdf',
        $grant,
        'fro_' . str_repeat('O', 24)
    );
    $verified = $codec->verifyOffer($offer);
    replicaOk($verified['target_node_id'] === $provider->nodeId(), 'verifica oferta firmada para provider exacto');
    replicaOk($verified['role'] === 'mirror', 'conserva role mirror autorizado');

    $tampered = $offer;
    $tampered['size_bytes'] = 1235;
    $rejected = false;
    try { $codec->verifyOffer($tampered); } catch (FederationException) { $rejected = true; }
    replicaOk($rejected, 'rechaza oferta de réplica alterada');

    $selectedGrant = FederationProviderGrant::sign(
        $origin,
        $provider->nodeId(),
        'mirror',
        'selected_resources'
    );
    $selected = $offer;
    $selected['provider_grant'] = $selectedGrant;
    unset($selected['signature']);
    $selected['signature'] = ArcadeCloud\Drive\Federation\FederationCodec::base64UrlEncode(
        $origin->sign(ArcadeCloud\Drive\Federation\FederationCodec::canonicalJson($selected))
    );
    $selectedRejected = false;
    try { $codec->verifyOffer($selected); } catch (FederationException) { $selectedRejected = true; }
    replicaOk($selectedRejected, 'replicación automática rechaza scope selected_resources');

    $selector = new FederationLocationSelector();
    $ordered = $selector->ordered([
        ['node_id' => $origin->nodeId(), 'role' => 'origin', 'status' => 'active', 'federation_url' => 'https://origin.example.test/federationcloud/'],
        ['node_id' => $provider->nodeId(), 'role' => 'mirror', 'status' => 'active', 'federation_url' => 'https://mirror.example.test/federationcloud/'],
        ['node_id' => 'acn_' . str_repeat('P', 24), 'role' => 'provider', 'status' => 'stale', 'federation_url' => 'https://stale.example.test/federationcloud/'],
    ]);
    replicaOk(($ordered[0]['role'] ?? '') === 'mirror', 'prefiere mirror activo antes que origin');
    replicaOk(($ordered[1]['role'] ?? '') === 'origin', 'mantiene origin activo como failover siguiente');
    replicaOk(($ordered[2]['status'] ?? '') === 'stale', 'deja ubicaciones stale al final');

    echo "federation replica smoke: OK\n";
} finally {
    @unlink($originPath);
    @unlink($providerPath);
    @rmdir($dir);
}
