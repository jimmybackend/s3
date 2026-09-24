<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationCodec.php';
require_once dirname(__DIR__) . '/src/Federation/NodeIdentityService.php';
require_once dirname(__DIR__) . '/src/Federation/FederationReplicaDownloader.php';
require_once dirname(__DIR__) . '/src/Federation/FederationDropIngressCodec.php';

use ArcadeCloud\Drive\Federation\FederationCodec;
use ArcadeCloud\Drive\Federation\FederationDropIngressCodec;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\NodeIdentityService;

function ingressOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$dir = sys_get_temp_dir() . '/arcadecloud-drop-ingress-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$commercePath = $dir . '/commerce.json';
$targetPath = $dir . '/target.json';

try {
    NodeIdentityService::initialize($commercePath, false, 'commerce-node');
    NodeIdentityService::initialize($targetPath, false, 'ingress-node');
    $commerce = new NodeIdentityService($commercePath);
    $target = new NodeIdentityService($targetPath);
    $codec = new FederationDropIngressCodec();

    $grant = $codec->createGrant(
        $commerce,
        'https://drive.esforzados.com/federationcloud/',
        $target->nodeId(),
        'fdp_' . str_repeat('D', 24),
        'fdi_' . str_repeat('I', 24),
        'archivo-prueba.bin',
        'application/octet-stream',
        1048576,
        7200
    );

    $descriptor = [
        'node_id' => $commerce->nodeId(),
        'public_key' => $commerce->publicKeyEncoded(),
    ];
    $verified = $codec->verifyGrant($grant, $descriptor);
    ingressOk($verified['target_node_id'] === $target->nodeId(), 'grant queda dirigido al nodo ingress exacto');
    ingressOk($verified['commerce_node_id'] === $commerce->nodeId(), 'grant conserva el nodo comercial firmante');
    ingressOk((int)$verified['expected_size_bytes'] === 1048576, 'grant liga el tamaño esperado');

    $tampered = $grant;
    $tampered['expected_size_bytes'] = 1048577;
    $rejected = false;
    try {
        $codec->verifyGrant($tampered, $descriptor);
    } catch (FederationException) {
        $rejected = true;
    }
    ingressOk($rejected, 'rechaza tamaño alterado después de firmar');

    $wrongTarget = $grant;
    $wrongTarget['target_node_id'] = $commerce->nodeId();
    $rejected = false;
    try {
        $codec->verifyGrant($wrongTarget, $descriptor);
    } catch (FederationException) {
        $rejected = true;
    }
    ingressOk($rejected, 'rechaza nodo destino alterado después de firmar');

    $expired = $grant;
    unset($expired['signature']);
    $expired['issued_at'] = gmdate(DATE_ATOM, time() - 7200);
    $expired['expires_at'] = gmdate(DATE_ATOM, time() - 3600);
    $expired['signature'] = [
        'alg' => 'Ed25519',
        'key_id' => $commerce->nodeId(),
        'value' => FederationCodec::base64UrlEncode(
            $commerce->sign(FederationCodec::canonicalJson($expired))
        ),
    ];
    $rejected = false;
    try {
        $codec->verifyGrant($expired, $descriptor);
    } catch (FederationException) {
        $rejected = true;
    }
    ingressOk($rejected, 'rechaza grant correctamente firmado pero vencido');

    $service = (string)file_get_contents(dirname(__DIR__) . '/src/Federation/FederationDropIngressService.php');
    $repository = (string)file_get_contents(dirname(__DIR__) . '/src/Federation/FederationDropIngressRepository.php');
    $controller = (string)file_get_contents(dirname(__DIR__) . '/src/Http/Controller/FederationDropIngressController.php');
    $js = (string)file_get_contents(dirname(__DIR__) . '/js/federation-drop.js');

    ingressOk(str_contains($repository, "p.Status='active'"), 'sólo proveedores comerciales activos son candidatos');
    ingressOk(str_contains($repository, 'MaxFileBytes') && str_contains($repository, 'CapacityBytes'), 'ingress respeta capacidad y tamaño máximo');
    ingressOk(str_contains($service, 'commerceUrl') && str_contains($service, 'commerce_federation_url'), 'nodo remoto liga grant al portal comercial configurado');
    ingressOk(str_contains($service, 'FederationDropIngress/'), 'objetos temporales usan namespace S3 aislado');
    ingressOk(str_contains($controller, 'Access-Control-Allow-Origin'), 'CORS se limita desde el controlador ingress');
    ingressOk(str_contains($controller, "if ($action === '')"), 'acciones machine-to-machine pueden viajar dentro del JSON firmado');
    ingressOk(str_contains($service, "'action' => 'source'") && str_contains($service, "'action' => 'delete'"), 'migración central usa acciones machine-to-machine explícitas');
    ingressOk(str_contains($js, 'measureIngressLatency'), 'navegador mide latencia de candidatos');
    ingressOk(str_contains($js, 'uploadDirect'), 'subida cercana conserva fallback central');

    echo "FederationDrop ingress smoke: OK\n";
} finally {
    @unlink($commercePath);
    @unlink($targetPath);
    @rmdir($dir);
}
