#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Federation\FederationAccessService;
use ArcadeCloud\Drive\Federation\FederationCustomsService;
use ArcadeCloud\Drive\Federation\FederationGossipService;
use ArcadeCloud\Drive\Federation\FederationReplicaService;
use ArcadeCloud\Drive\Federation\FederationShareDriveService;

$lockPath = sys_get_temp_dir() . '/arcadecloud-federation-sync.lock';
$lock = fopen($lockPath, 'c+');
if (!is_resource($lock)) {
    fwrite(STDERR, "No se pudo abrir lock de sincronización FederationCloud.\n");
    exit(2);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "FederationCloud sync: otra ejecución sigue activa; se omite este ciclo.\n";
    fclose($lock);
    exit(0);
}

try {
    $app = ApplicationKernel::app();

    // Aduana: como máximo UNA petición externa por ciclo. El lock de este worker
    // garantiza que no haya dos procesadores pesados concurrentes.
    try {
        $customs = (new FederationCustomsService($app))->processNext();
    } catch (Throwable $e) {
        $customs = [
            'degraded' => true,
            'error' => 'La Aduana FederationCloud no pudo procesar su siguiente petición.',
        ];
        error_log('[FederationCloud customs] ' . $e->getMessage());
    }

    // Después de Aduana, gossip ya puede ver un nodo recién admitido y empezar
    // a intercambiar su node.upsert firmado y el resto del catálogo.
    $result = (new FederationGossipService($app))->syncOnce();
    $result['customs'] = $customs;

    try {
        $result['access_requests'] = (new FederationAccessService($app))->syncPending(5);
    } catch (Throwable $e) {
        $result['access_requests'] = [
            'degraded' => true,
            'error' => 'La cola privada de solicitudes no pudo procesarse en este ciclo.',
        ];
        error_log('[FederationCloud access sync] ' . $e->getMessage());
    }
    try {
        // Bloques pequeños: hasta 3 ofertas salientes y 2 descargas entrantes por ciclo.
        $result['replicas'] = (new FederationReplicaService($app))->syncPending(3, 2);
    } catch (Throwable $e) {
        $result['replicas'] = [
            'degraded' => true,
            'error' => 'La cola de réplicas no pudo procesarse en este ciclo.',
        ];
        error_log('[FederationCloud replica sync] ' . $e->getMessage());
    }
    try {
        // Agregar a Mi Drive se hace fuera de PHP-FPM: una copia por ciclo.
        $result['share_imports'] = (new FederationShareDriveService($app))->syncPending(1);
    } catch (Throwable $e) {
        $result['share_imports'] = [
            'degraded' => true,
            'error' => 'La cola de Compartidos no pudo procesarse en este ciclo.',
        ];
        error_log('[FederationCloud Share import sync] ' . $e->getMessage());
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FederationCloud sync error: {$e->getMessage()}\n");
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

flock($lock, LOCK_UN);
fclose($lock);
