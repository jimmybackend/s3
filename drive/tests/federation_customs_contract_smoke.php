<?php
declare(strict_types=1);

function customsOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$root = dirname(__DIR__);
$sql = (string)file_get_contents($root . '/sql/federation_ingress_queue.sql');
$repo = (string)file_get_contents($root . '/src/Federation/FederationIngressQueueRepository.php');
$customs = (string)file_get_contents($root . '/src/Federation/FederationCustomsService.php');
$directory = (string)file_get_contents($root . '/src/Federation/FederationDirectoryService.php');
$nodeRepo = (string)file_get_contents($root . '/src/Federation/FederationNodeRepository.php');
$catalog = (string)file_get_contents($root . '/src/Federation/FederationCatalogService.php');
$providerController = (string)file_get_contents($root . '/src/Http/Controller/FederationProviderController.php');
$providerService = (string)file_get_contents($root . '/src/Federation/FederationProviderAuthorizationService.php');
$sync = (string)file_get_contents($root . '/bin/federation_sync.php');
$syncInstaller = (string)file_get_contents($root . '/bin/install_federation_sync_timer.sh');
$endpointRefresh = (string)file_get_contents($root . '/bin/federation_endpoint_refresh.php');
$migrate = (string)file_get_contents($root . '/bin/federation_catalog_migrate.php');

customsOk(str_contains($sql, 'CREATE TABLE IF NOT EXISTS FederationIngressQueue'), 'Aduana persiste en MySQL');
customsOk(str_contains($sql, 'UNIQUE KEY uq_federation_ingress_request (RequestId)'), 'RequestId es idempotente');
customsOk(str_contains($sql, "enum('queued','processing','retry','done','rejected','failed')"), 'cola documenta estados de proceso');
customsOk(str_contains($repo, "LIMIT 1 FOR UPDATE"), 'claim de cola serializa la siguiente petición');
customsOk(str_contains($repo, "ORDER BY Priority ASC, ReceivedAt ASC, id_ ASC"), 'Aduana conserva prioridad y FIFO');
customsOk(str_contains($repo, 'recoverStale'), 'Aduana recupera trabajos interrumpidos');

customsOk(str_contains($customs, "'node_presence'"), 'Aduana acepta presencia independiente');
customsOk(str_contains($customs, "'shared_backend_authorization'"), 'Aduana separa autorización privilegiada');
customsOk(str_contains($customs, "'requires_superadmin' => false"), 'presencia independiente no requiere superadmin');
customsOk(str_contains($customs, "'requires_superadmin' => true"), 'backend compartido requiere superadmin');
customsOk(str_contains($customs, 'processNext()'), 'Aduana expone procesamiento unitario');
customsOk(substr_count($sync, '->processNext()') === 1, 'worker procesa como máximo una petición de Aduana por ciclo');
customsOk(str_contains($sync, 'LOCK_EX | LOCK_NB'), 'worker FederationCloud conserva lock exclusivo');
customsOk(strpos($sync, '->processNext()') < strpos($sync, '->syncOnce()'), 'Aduana procesa antes de gossip');

customsOk(str_contains($directory, 'enqueueNodePresence'), 'register.php enruta presencia por Aduana');
customsOk(str_contains($directory, "'registration'"), 'directorio informa estado de registro en cola');
customsOk(str_contains($directory, "getJson(\$this->seeds->primary(), 'node.php')"), 'nodo nuevo obtiene descriptor del seed para bootstrap gossip');

customsOk(str_contains($providerController, "\$relationship !== 'shared_backend'"), 'provider-request se reserva a backend compartido');
customsOk(str_contains($providerController, 'enqueueSharedBackendAuthorization'), 'provider-request entra por Aduana');
customsOk(str_contains($providerController, 'JsonResponse::send($result, 202)'), 'petición privilegiada responde 202 Accepted');
customsOk(str_contains($providerController, 'isSuperAdmin'), 'decisión privilegiada sigue restringida a superadmin');
customsOk(str_contains($providerService, "'relationship' => 'shared_backend'"), 'cliente declara relación privilegiada explícitamente');
customsOk(str_contains($providerService, 'FederationNodeAuthorizations'), false === true ? '' : 'flujo existente conserva repositorio de autorizaciones');

customsOk(str_contains($nodeRepo, "PublicUrl = ?, FederationUrl = ?"), 'mismo Node ID puede rotar endpoint firmado');
customsOk(str_contains($nodeRepo, "existing['PublicKey']"), 'clave pública permanece binding inmutable');
customsOk(str_contains($catalog, "'availability_bucket' => (int)floor(time() / 300)"), 'node.upsert replica heartbeat cada cinco minutos');
customsOk(str_contains($endpointRefresh, 'announceNodeIfChanged'), 'arranque/refresh deja presencia firmada para gossip');

customsOk(str_contains($syncInstaller, 'OnActiveSec=45s'), 'sync arranca incluso si la ventana de boot ya pasó');
customsOk(str_contains($syncInstaller, 'OnUnitInactiveSec=${INTERVAL_SEC}s'), 'worker oneshot se reprograma tras finalizar');
customsOk(!str_contains($syncInstaller, 'OnUnitActiveSec=${INTERVAL_SEC}s'), 'sync no usa temporizador incompatible con oneshot');
customsOk(str_contains($migrate, 'federation_ingress_queue.sql'), 'migración instala la cola de Aduana');

customsOk(!preg_match('/AKIA[0-9A-Z]{16}/', $customs . $repo . $sql), 'Aduana no contiene credenciales AWS');
customsOk(!str_contains($customs, 'secret_key'), 'Aduana no serializa clave privada de nodo');
customsOk(!str_contains($customs, 'payload_key'), 'Aduana no serializa payload key');
customsOk(!str_contains($customs, 'DB_PASSWORD'), 'Aduana no serializa credenciales DB');

fwrite(STDOUT, "Federation customs contract smoke: OK\n");
