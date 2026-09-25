<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationLocationSelector.php';
require_once dirname(__DIR__) . '/src/Federation/FederationSourceFailover.php';

use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationLocationSelector;
use ArcadeCloud\Drive\Federation\FederationSourceFailover;

function publicDownloadOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

function publicDownloadNode(string $suffix, string $role = 'provider', string $status = 'active'): array
{
    return [
        'node_id' => 'acn_' . str_repeat($suffix, 24),
        'role' => $role,
        'status' => $status,
        'federation_url' => 'https://' . strtolower($suffix) . '.example.test/federationcloud/',
    ];
}

function publicDownloadSuccess(array $candidate): array
{
    return [
        'node_id' => (string)$candidate['node_id'],
        'role' => (string)$candidate['role'],
        'url' => 'https://synthetic-bucket.s3.us-east-1.amazonaws.com/object?X-Amz-Signature=synthetic',
    ];
}

$failover = new FederationSourceFailover(new FederationLocationSelector());
$origin = publicDownloadNode('O', 'origin');

// CASO 1: un candidato que responde es suficiente.
$single = $failover->candidates([], $origin);
$result = $failover->collect($single, 1, static fn(array $candidate): array => publicDownloadSuccess($candidate));
publicDownloadOk(count($result['sources']) === 1, 'CASE 1: un nodo candidato que responde produce éxito');
publicDownloadOk(($result['sources'][0]['node_id'] ?? '') === $origin['node_id'], 'CASE 1: el único origen es utilizable');

// CASO 2: un candidato que falla agota candidatos y deja fallo limpio.
$result = $failover->collect(
    $single,
    1,
    static function (array $candidate): array {
        throw new FederationException('El nodo FederationCloud no respondió correctamente.', 502);
    }
);
publicDownloadOk($result['sources'] === [], 'CASE 2: un único nodo fallido no produce fuente');
publicDownloadOk(count($result['failures']) === 1, 'CASE 2: registra exactamente el fallo del único candidato');

// CASO 3: tres nodos, falla el primero y responde el segundo.
$three = [
    publicDownloadNode('A', 'mirror'),
    publicDownloadNode('B', 'provider'),
    $origin,
];
$attempts = [];
$result = $failover->collect(
    $three,
    1,
    static function (array $candidate) use (&$attempts): array {
        $attempts[] = $candidate['node_id'];
        if (count($attempts) === 1) throw new FederationException('offline', 502);
        return publicDownloadSuccess($candidate);
    }
);
publicDownloadOk(count($result['sources']) === 1 && count($result['failures']) === 1, 'CASE 3: falla primero y el segundo responde');
publicDownloadOk(($result['sources'][0]['node_id'] ?? '') === $three[1]['node_id'], 'CASE 3: el segundo candidato entrega el recurso');

// CASO 4: fallan primero y segundo, responde el tercero.
$attempts = [];
$result = $failover->collect(
    $three,
    1,
    static function (array $candidate) use (&$attempts): array {
        $attempts[] = $candidate['node_id'];
        if (count($attempts) <= 2) throw new FederationException('timeout', 503);
        return publicDownloadSuccess($candidate);
    }
);
publicDownloadOk(count($result['sources']) === 1 && count($result['failures']) === 2, 'CASE 4: continúa hasta el tercer candidato');
publicDownloadOk(($result['sources'][0]['node_id'] ?? '') === $origin['node_id'], 'CASE 4: el tercer candidato puede ser el origen');

// CASO 5: todos los nodos fallan.
$result = $failover->collect(
    $three,
    1,
    static function (array $candidate): array {
        throw new FederationException('El candidato no respondió.', 502);
    }
);
publicDownloadOk($result['sources'] === [], 'CASE 5: todos los candidatos fallidos producen cero fuentes');
publicDownloadOk(count($result['failures']) === 3, 'CASE 5: no declara éxito mientras todos hayan fallado');

// CASO 6: el catálogo conoce otro nodo pero omitió la fila location del origen.
// El bug de PR #133 fallaba aquí: la mera existencia de cualquier location impedía
// añadir el origen como fallback.
$providerOnly = [publicDownloadNode('P', 'provider')];
$planned = $failover->candidates($providerOnly, $origin);
$plannedIds = array_column($planned, 'node_id');
publicDownloadOk(in_array($origin['node_id'], $plannedIds, true), 'CASE 6: el origen se agrega aunque ya exista otro candidato');
$result = $failover->collect(
    $planned,
    1,
    static function (array $candidate) use ($origin): array {
        if (!hash_equals($origin['node_id'], (string)$candidate['node_id'])) {
            throw new FederationException('provider offline', 502);
        }
        return publicDownloadSuccess($candidate);
    }
);
publicDownloadOk(($result['sources'][0]['node_id'] ?? '') === $origin['node_id'], 'CASE 6: origen único saludable funciona como fallback real');

// CASO 7: candidatos inválidos/no autorizados no deben bloquear al origen.
$invalidOriginRow = $origin;
$invalidOriginRow['role'] = 'rogue';
$planned = $failover->candidates([$invalidOriginRow], $origin);
publicDownloadOk(
    count($planned) === 1 && ($planned[0]['role'] ?? '') === 'origin',
    'CASE 7: una fila inválida con Node ID del origen no tapa el origen firmado'
);
$unauthorized = publicDownloadNode('U', 'provider');
$planned = $failover->candidates([$unauthorized], $origin);
$result = $failover->collect(
    $planned,
    1,
    static function (array $candidate) use ($origin): array {
        if (!hash_equals($origin['node_id'], (string)$candidate['node_id'])) {
            throw new FederationException('Acceso rechazado.', 403);
        }
        return publicDownloadSuccess($candidate);
    }
);
publicDownloadOk(($result['sources'][0]['node_id'] ?? '') === $origin['node_id'], 'CASE 7: candidato no autorizado se omite y continúa');
publicDownloadOk(($result['failures'][0]['category'] ?? '') === 'access_rejected', 'CASE 7: rechazo de acceso queda clasificado internamente');

// CASO 8: no existe dependencia artificial de dos nodos/quorum.
$helperSource = (string)file_get_contents(dirname(__DIR__) . '/src/Federation/FederationSourceFailover.php');
$resolverSource = (string)file_get_contents(dirname(__DIR__) . '/src/Federation/FederationReplicaResolverService.php');
$combined = strtolower($helperSource . "\n" . $resolverSource);
publicDownloadOk(!str_contains($combined, 'minimum_nodes'), 'CASE 8: no existe minimum_nodes');
publicDownloadOk(!str_contains($combined, 'quorum'), 'CASE 8: no existe quorum');
publicDownloadOk(!str_contains($combined, 'majority'), 'CASE 8: no existe majority');
publicDownloadOk(
    preg_match('/count\s*\([^)]*\)\s*(?:<|<=|>=|>)\s*2\b/', $combined) !== 1,
    'CASE 8: no hay condición count(nodes) contra 2'
);

// CASO 9: un nodo lento tiene límites y no puede bloquear indefinidamente el siguiente.
$httpSource = (string)file_get_contents(dirname(__DIR__) . '/src/Federation/FederationHttpClient.php');
$probeSource = (string)file_get_contents(dirname(__DIR__) . '/src/Federation/FederationReplicaDownloader.php');
publicDownloadOk(str_contains($httpSource, 'CURLOPT_CONNECTTIMEOUT_MS => 2000'), 'CASE 9: conexión FederationCloud limitada a 2 s');
publicDownloadOk(str_contains($httpSource, 'CURLOPT_TIMEOUT_MS => 5000'), 'CASE 9: intento FederationCloud limitado a 5 s');
publicDownloadOk(str_contains($probeSource, 'CURLOPT_CONNECTTIMEOUT_MS => 3000'), 'CASE 9: conexión de prueba S3 limitada a 3 s');
publicDownloadOk(str_contains($probeSource, 'CURLOPT_TIMEOUT => 10'), 'CASE 9: prueba S3 limitada a 10 s');

// CASO 10: el arreglo sigue encapsulado en FederationCloud y no reconstruye por S3.
$controllerSource = (string)file_get_contents(dirname(__DIR__) . '/src/Http/Controller/FederationReplicaController.php');
publicDownloadOk(str_contains($resolverSource, 'requirePublicCopyable'), 'CASE 10: conserva política PUBLIC + copy_allowed');
publicDownloadOk(str_contains($resolverSource, "'replica-resolve.php'"), 'CASE 10: conserva resolución FederationCloud máquina-a-máquina');
publicDownloadOk(str_contains($resolverSource, '->probe('), 'CASE 10: conserva prueba real de la fuente S3');
publicDownloadOk(str_contains($controllerSource, 'No fue posible obtener el archivo desde los nodos disponibles.'), 'CASE 10: error público no revela detalle interno');
publicDownloadOk(!str_contains($helperSource . $resolverSource, 'listObjects'), 'CASE 10: el arreglo no lista S3');
publicDownloadOk(!str_contains($helperSource . $resolverSource, 'ListObjects'), 'CASE 10: el arreglo no usa ListObjects');

fwrite(STDOUT, "Federation public download failover smoke: OK\n");
