<?php
declare(strict_types=1);

function reconnectOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$root = dirname(__DIR__);
$config = (string)file_get_contents($root . '/src/Federation/FederationConfig.php');
$http = (string)file_get_contents($root . '/src/Federation/FederationHttpClient.php');
$service = (string)file_get_contents($root . '/src/Federation/FederationProviderAuthorizationService.php');
$repo = (string)file_get_contents($root . '/src/Federation/FederationProviderAuthorizationRepository.php');
$presence = (string)file_get_contents($root . '/src/Federation/FederationReplicaPresenceService.php');
$controller = (string)file_get_contents($root . '/src/Http/Controller/FederationProviderController.php');
$endpoint = (string)file_get_contents($root . '/federationcloud/provider-presence.php');
$sync = (string)file_get_contents($root . '/bin/federation_sync.php');
$refresh = (string)file_get_contents($root . '/bin/federation_endpoint_refresh.php');
$requestCli = (string)file_get_contents($root . '/bin/federation_provider_request.php');
$replicas = (string)file_get_contents($root . '/src/Federation/FederationReplicaService.php');

reconnectOk(str_contains($config, 'ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL'), 'la réplica declara explícitamente su origen');
reconnectOk(str_contains($config, 'replicaOriginUrl()'), 'configuración expone origen de réplica sin afectar nodos normales');
reconnectOk(str_contains($http, "'provider-presence.php'"), 'cliente federado permite la puerta de presencia');
reconnectOk(str_contains($http, '$http < 200 || $http >= 300'), 'cliente acepta respuestas JSON 2xx como 202 Accepted');

reconnectOk(str_contains($endpoint, '->presenceApi()'), 'provider-presence.php permanece endpoint delgado');
reconnectOk(str_contains($controller, 'public function presenceApi()'), 'controller expone presencia de réplica');
reconnectOk(str_contains($controller, "!== 'shared_backend'"), 'puerta rápida sólo acepta backend compartido');
reconnectOk(str_contains($controller, '->receivePresence('), 'controller delega reactivación al servicio OOP');

reconnectOk(str_contains($service, 'public function receivePresence('), 'servicio separa presencia de solicitud inicial');
reconnectOk(str_contains($service, "'authorization_required'"), 'ausencia de autorización no concede acceso');
reconnectOk(str_contains($service, "\$status !== 'active'"), 'pending/revoked/blocked no entran al fast path');
reconnectOk(str_contains($service, '->touchActive('), 'fast path actualiza LastSeen sólo de autorización activa');
reconnectOk(str_contains($service, "'available' => true"), 'reactivación activa marca disponibilidad');
reconnectOk(str_contains($service, 'allActiveForOrigin'), 'administración conserva autorizaciones aunque estén offline');

reconnectOk(str_contains($repo, 'public function allActiveForOrigin('), 'repositorio distingue autorización permanente');
reconnectOk(str_contains($repo, 'public function activeAvailableForOrigin('), 'repositorio distingue disponibilidad temporal');
reconnectOk(str_contains($repo, 'TIMESTAMPDIFF(SECOND, a.LastSeen, UTC_TIMESTAMP()) <= ?'), 'disponibilidad depende de LastSeen reciente');
reconnectOk(str_contains($repo, 'DEFAULT_AVAILABILITY_SECONDS = 900'), 'ventana de disponibilidad es 15 minutos');
reconnectOk(str_contains($replicas, '->activeForOrigin('), 'asignación de nuevas réplicas usa sólo autorizados disponibles');

reconnectOk(str_contains($presence, "postJson(\$originUrl, 'provider-presence.php'"), 'la copia se presenta por iniciativa propia');
reconnectOk(str_contains($presence, "if (\$status === 'active')"), 'autorizada entra directamente sin nueva Solicitud');
reconnectOk(str_contains($presence, "if (in_array(\$status, ['pending', 'blocked', 'revoked'], true))"), 'estados restringidos no crean solicitudes automáticas');
reconnectOk(str_contains($presence, "if (\$status !== 'authorization_required')"), 'sólo ausencia real de autorización dispara alta inicial');
reconnectOk(str_contains($presence, "postJson(\$originUrl, 'provider-request.php'"), 'primera alta sí pasa por Aduana/Solicitudes');
reconnectOk(str_contains($presence, "'relationship' => 'shared_backend'"), 'la copia identifica relación privilegiada explícitamente');
reconnectOk(str_contains($presence, '->upsertVerified($origin)'), 'la copia aprende el origen como peer para gossip');

$presencePos = strpos($sync, 'FederationReplicaPresenceService');
$customsPos = strpos($sync, 'FederationCustomsService');
$gossipPos = strpos($sync, 'FederationGossipService');
reconnectOk($presencePos !== false && $customsPos !== false && $gossipPos !== false && $presencePos < $gossipPos, 'worker refresca presencia antes de gossip');
reconnectOk(str_contains($refresh, 'FederationReplicaPresenceService'), 'arranque/cambio de endpoint reanuncia réplica');
reconnectOk(str_contains($requestCli, "'relationship' => 'shared_backend'"), 'CLI de alta inicial declara relación compartida');

$securityCorpus = $presence . $service . $controller . $endpoint;
reconnectOk(!preg_match('/AKIA[0-9A-Z]{16}/', $securityCorpus), 'reconexión no contiene credenciales AWS');
reconnectOk(!str_contains($securityCorpus, 'aws_secret_access_key'), 'reconexión no transporta AWS secret');
reconnectOk(!str_contains($presence, 'DB_PASSWORD'), 'anuncio de réplica no transporta credenciales DB');
reconnectOk(!str_contains($presence, 'secret_key'), 'anuncio de réplica no transporta clave privada Ed25519');
reconnectOk(!str_contains($presence, 'payload_key'), 'anuncio de réplica no transporta payload key');

fwrite(STDOUT, "Federation replica reconnect contract smoke: OK\n");
