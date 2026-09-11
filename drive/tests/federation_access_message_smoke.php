<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';
require_once dirname(__DIR__) . '/src/Federation/FederationAccessMessageCodec.php';

use ArcadeCloud\Drive\Federation\FederationAccessMessageCodec;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

function accessOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

$dir = sys_get_temp_dir() . '/arcadecloud-access-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$requesterPath = $dir . '/requester.json';
$originPath = $dir . '/origin.json';
try {
    NodeIdentityService::initialize($requesterPath, false, 'requester');
    NodeIdentityService::initialize($originPath, false, 'esforzados');
    $requester = new NodeIdentityService($requesterPath);
    $origin = new NodeIdentityService($originPath);
    $codec = new FederationAccessMessageCodec();
    $requestedAt = gmdate(DATE_ATOM);
    $expiresAt = gmdate(DATE_ATOM, time() + 86400);
    $request = $codec->createRequest(
        $requester,
        'arl_' . str_repeat('A', 24),
        'https://requester.example.test/federationcloud/',
        'far_' . str_repeat('B', 24),
        $requestedAt,
        $expiresAt
    );
    $verified = $codec->verifyRequest($request);
    accessOk($verified['requester_node_id'] === $requester->nodeId(), 'verifica solicitud firmada por nodo solicitante');

    $tampered = $request;
    $tampered['resource_id'] = 'arl_' . str_repeat('C', 24);
    $rejected = false;
    try { $codec->verifyRequest($tampered); } catch (FederationException) { $rejected = true; }
    accessOk($rejected, 'rechaza solicitud alterada');

    $grantExpires = gmdate(DATE_ATOM, time() + 3600);
    $decision = $codec->createDecision(
        $origin,
        $request,
        'approved',
        'https://origin.example.test/token_texto.php?t=synthetic',
        $grantExpires
    );
    $verifiedDecision = $codec->verifyDecision($decision, $request);
    accessOk($verifiedDecision['origin_node_id'] === $origin->nodeId(), 'verifica grant firmado por nodo origen');
    accessOk($verifiedDecision['decision'] === 'approved', 'conserva decisión aprobada');

    $rejectedDecision = $codec->createDecision($origin, $request, 'rejected');
    accessOk($codec->verifyDecision($rejectedDecision, $request)['decision'] === 'rejected', 'verifica rechazo firmado sin URL');

    echo "federation access message smoke: OK\n";
} finally {
    @unlink($requesterPath);
    @unlink($originPath);
    @rmdir($dir);
}
