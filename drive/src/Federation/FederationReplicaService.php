<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationReplicaService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederatedCatalogRepository $catalog;
    private FederationReplicaRepository $replicas;
    private FederationProviderAuthorizationRepository $authorizations;
    private FederationReplicaMessageCodec $codec;
    private FederationHttpClient $http;
    private FederationEventStore $events;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->catalog = new FederatedCatalogRepository($app->db());
        $this->replicas = new FederationReplicaRepository($app->db());
        $this->authorizations = new FederationProviderAuthorizationRepository($app->db());
        $this->codec = new FederationReplicaMessageCodec();
        $this->http = new FederationHttpClient();
        $this->events = new FederationEventStore($app->db(), $this->catalog, new FederationEventCodec());
    }

    public function queueForResource(int $userId, string $resourceId, int $copies = 2): array
    {
        $this->ensureEnabled();
        $copies = max(1, min(3, $copies));
        $resource = $this->requireLocalOwnedResource($userId, $resourceId);
        if (!hash_equals('copy_allowed', (string)$resource['Rights'])) {
            throw new FederationException('El recurso debe usar rights=copy_allowed para crear réplicas físicas.', 409);
        }
        $contentId = strtolower(trim((string)($resource['ContentId'] ?? '')));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) {
            throw new FederationException('La réplica requiere Content ID SHA-256 previamente conocido.', 409);
        }
        $size = (int)$resource['SizeBytes'];
        if ($size < 0 || $size > FederationReplicaDownloader::MAX_BYTES) {
            throw new FederationException('El recurso excede el límite de réplica de 5 GiB de esta versión.', 413);
        }

        $document = $this->decodeArcadeLink((string)($resource['ArcadeLinkJson'] ?? ''));
        $verified = (new ArcadeLinkService($this->config, $this->identity))->parse(FederationCodec::canonicalJson($document));
        $payload = (new ArcadeLinkService($this->config, $this->identity))->decryptLocalPayload($verified);
        $storageRef = trim((string)($payload['storage_ref'] ?? ''));
        if ($storageRef === '') throw new FederationException('El recurso no conserva referencia física local.', 409);

        $locations = $this->catalog->locations($resourceId);
        $already = [];
        foreach ($locations as $location) {
            if ((string)($location['status'] ?? '') === 'active') $already[(string)$location['node_id']] = true;
        }

        $providers = array_values(array_filter(
            $this->authorizations->activeForOrigin($this->identity->nodeId()),
            static fn(array $row): bool => (string)$row['Scope'] === 'all_allowed_resources'
                && in_array((string)$row['Role'], FederationProviderGrant::ROLES, true)
                && !isset($already[(string)$row['ProviderNodeId']])
        ));
        usort($providers, static function (array $a, array $b) use ($resourceId): int {
            $ha = hash('sha256', $resourceId . '|' . (string)$a['ProviderNodeId']);
            $hb = hash('sha256', $resourceId . '|' . (string)$b['ProviderNodeId']);
            return strcmp($hb, $ha);
        });

        $queued = [];
        foreach (array_slice($providers, 0, $copies) as $provider) {
            $nodeId = (string)$provider['ProviderNodeId'];
            $offerId = $this->stableOfferId($resourceId, $nodeId);
            $this->replicas->queueOutgoing([
                'offer_id' => $offerId,
                'resource_id' => $resourceId,
                'user_id' => $userId,
                'remote_node_id' => $nodeId,
                'remote_federation_url' => (string)$provider['FederationUrl'],
                'role' => (string)$provider['Role'],
                'storage_ref' => $storageRef,
                'content_id' => $contentId,
                'size_bytes' => $size,
                'title' => (string)$resource['Title'],
                'media_type' => (string)$resource['MediaType'],
            ]);
            $queued[] = ['offer_id' => $offerId, 'node_id' => $nodeId, 'role' => (string)$provider['Role']];
        }

        return [
            'ok' => true,
            'resource_id' => $resourceId,
            'requested_copies' => $copies,
            'eligible_providers' => count($providers),
            'queued' => $queued,
            'message' => $queued === []
                ? 'No hay proveedores activos elegibles que aún no almacenen este recurso.'
                : 'Réplicas encoladas. El worker las distribuirá por bloques sin cargar PHP del nodo origen con los bytes.',
        ];
    }

    public function receiveOffer(array $offer): array
    {
        $this->ensureEnabled();
        $offer = $this->codec->verifyOffer($offer);
        if (!hash_equals($this->identity->nodeId(), (string)$offer['target_node_id'])) {
            throw new FederationException('La oferta de réplica está dirigida a otro nodo.', 409);
        }
        $resource = $this->catalog->find((string)$offer['resource_id']);
        if ($resource === null) throw new FederationException('El recurso todavía no existe en el catálogo local; sincroniza catálogo y reintenta.', 409);
        if (!hash_equals((string)$resource['OriginNodeId'], (string)$offer['origin_node_id'])
            || !hash_equals(strtolower((string)$resource['ContentId']), strtolower((string)$offer['content_id']))
            || (int)$resource['SizeBytes'] !== (int)$offer['size_bytes']) {
            throw new FederationException('La oferta no coincide con el recurso global conocido.', 409);
        }
        if (!hash_equals('copy_allowed', (string)$resource['Rights'])) {
            throw new FederationException('El recurso global no autoriza copia física.', 403);
        }
        $status = $this->replicas->storeIncoming($offer, (string)$resource['FederationUrl']);
        return ['ok' => true, 'status' => $status, 'offer_id' => (string)$offer['offer_id']];
    }

    public function syncPending(int $outgoingLimit = 3, int $incomingLimit = 2): array
    {
        $this->ensureEnabled();
        $expired = $this->replicas->expireOld();
        $outgoing = $this->syncOutgoing($outgoingLimit);
        $incoming = $this->syncIncoming($incomingLimit);
        return ['expired' => $expired, 'outgoing' => $outgoing, 'incoming' => $incoming];
    }

    public function jobsForUser(int $userId): array
    {
        return ['ok' => true, 'jobs' => $this->replicas->jobsForUser($userId)];
    }

    public function publicReplica(string $resourceId): array
    {
        $this->ensureEnabled();
        $resource = $this->catalog->find($resourceId);
        $object = $this->replicas->object($resourceId);
        if ($resource === null || $object === null || (string)$object['Status'] !== 'active') {
            throw new FederationException('Este nodo no tiene una réplica activa del recurso.', 404);
        }
        if ((string)$resource['Visibility'] !== 'PUBLIC' || (string)$resource['Rights'] !== 'copy_allowed') {
            throw new FederationException('La réplica no tiene acceso público directo.', 403);
        }
        return [
            'ok' => true,
            'resource_id' => $resourceId,
            'node_id' => $this->identity->nodeId(),
            'role' => (string)$object['Role'],
            'content_id' => (string)$object['ContentId'],
            'size_bytes' => (int)$object['SizeBytes'],
            'access_url' => $this->app->shareObjectStorage()->presignedUrl((string)$object['S3Key'], '+5 minutes'),
            'expires_in' => 300,
        ];
    }

    private function syncOutgoing(int $limit): array
    {
        $processed = $offered = $active = $errors = 0;
        foreach ($this->replicas->due('outgoing', $limit) as $job) {
            $processed++;
            $offerId = (string)$job['OfferId'];
            try {
                if ($this->targetIsActive((string)$job['ResourceId'], (string)$job['RemoteNodeId'])) {
                    $this->replicas->markActive($offerId);
                    $active++;
                    continue;
                }
                $authorization = $this->authorizations->find($this->identity->nodeId(), (string)$job['RemoteNodeId']);
                if ($authorization === null || (string)$authorization['Status'] !== 'active'
                    || (string)$authorization['Scope'] !== 'all_allowed_resources'
                    || !hash_equals((string)$authorization['Role'], (string)$job['Role'])) {
                    throw new FederationException('El proveedor ya no tiene una autorización activa compatible.', 403);
                }
                $grant = FederationProviderGrant::payload(
                    $this->identity->nodeId(),
                    (string)$job['RemoteNodeId'],
                    (string)$authorization['Role'],
                    (string)$authorization['Scope']
                );
                $grant['signature'] = [
                    'alg' => 'Ed25519',
                    'key_id' => $this->identity->nodeId(),
                    'value' => (string)$authorization['OriginSignature'],
                ];
                $sourceUrl = $this->app->shareObjectStorage()->presignedUrl((string)$job['SourceStorageRef'], '+10 minutes');
                $offer = $this->codec->createOffer(
                    $this->identity,
                    (string)$job['RemoteNodeId'],
                    (string)$job['Role'],
                    (string)$job['ResourceId'],
                    $sourceUrl,
                    (string)$job['ContentId'],
                    (int)$job['SizeBytes'],
                    (string)$job['Title'],
                    (string)$job['MediaType'],
                    $grant,
                    $offerId
                );
                $response = $this->http->postJson((string)$job['RemoteFederationUrl'], 'replica-offer.php', ['offer' => $offer]);
                if (empty($response['ok'])) throw new FederationException((string)($response['error'] ?? 'El proveedor rechazó la réplica.'), 502);
                $this->replicas->markOffered($offerId);
                $offered++;
            } catch (Throwable $e) {
                $this->replicas->markRetry($offerId, $e->getMessage());
                $errors++;
            }
        }
        return compact('processed', 'offered', 'active', 'errors');
    }

    private function syncIncoming(int $limit): array
    {
        $processed = $stored = $active = $errors = 0;
        foreach ($this->replicas->due('incoming', $limit) as $job) {
            $processed++;
            $offerId = (string)$job['OfferId'];
            try {
                if ((string)$job['Status'] !== 'stored') {
                    $offer = json_decode((string)$job['OfferJson'], true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($offer) || array_is_list($offer)) throw new FederationException('Oferta almacenada inválida.', 500);
                    $offer = $this->codec->verifyOffer($offer);
                    $this->replicas->markTransferring($offerId);
                    $download = (new FederationReplicaDownloader())->download(
                        (string)$offer['source_url'],
                        (int)$offer['size_bytes'],
                        (string)$offer['content_id']
                    );
                    $tmp = (string)$download['path'];
                    try {
                        $s3Key = $this->replicaS3Key((string)$offer['origin_node_id'], (string)$offer['resource_id']);
                        $this->app->s3()->putObject([
                            'Bucket' => $this->app->bucket(),
                            'Key' => $s3Key,
                            'SourceFile' => $tmp,
                            'ContentType' => (string)$offer['media_type'],
                            'Metadata' => [
                                'federation-resource-id' => (string)$offer['resource_id'],
                                'federation-origin-node' => (string)$offer['origin_node_id'],
                                'sha256' => substr((string)$offer['content_id'], 7),
                            ],
                        ]);
                        $head = $this->app->s3()->headObject(['Bucket' => $this->app->bucket(), 'Key' => $s3Key]);
                        if ((int)($head['ContentLength'] ?? -1) !== (int)$offer['size_bytes']) {
                            throw new FederationException('S3 receptor no confirmó el tamaño de la réplica.', 500);
                        }
                        $this->replicas->upsertObject([
                            'resource_id' => (string)$offer['resource_id'],
                            'origin_node_id' => (string)$offer['origin_node_id'],
                            'role' => (string)$offer['role'],
                            's3_key' => $s3Key,
                            'content_id' => (string)$offer['content_id'],
                            'size_bytes' => (int)$offer['size_bytes'],
                        ]);
                        $this->replicas->markStored($offerId, $s3Key);
                        $stored++;
                    } finally {
                        @unlink($tmp);
                    }
                }
                $this->announceStored($this->replicas->job($offerId) ?? $job);
                $this->replicas->markActive($offerId);
                $this->replicas->activateObject((string)$job['ResourceId']);
                $active++;
            } catch (Throwable $e) {
                $this->replicas->markRetry($offerId, $e->getMessage());
                $errors++;
            }
        }
        return compact('processed', 'stored', 'active', 'errors');
    }

    private function announceStored(array $job): void
    {
        $resourceId = (string)$job['ResourceId'];
        $this->events->emit($this->identity, 'location.upsert', $resourceId . '@' . $this->identity->nodeId(), [
            'resource_id' => $resourceId,
            'node_id' => $this->identity->nodeId(),
            'role' => (string)$job['Role'],
            'status' => 'active',
            'federation_url' => $this->config->federationUrl(),
        ]);
    }

    private function requireLocalOwnedResource(int $userId, string $resourceId): array
    {
        if ($userId <= 0 || !preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            throw new FederationException('Recurso de réplica inválido.', 400);
        }
        $resource = $this->catalog->find($resourceId);
        if ($resource === null || !hash_equals($this->identity->nodeId(), (string)$resource['OriginNodeId'])
            || (int)($resource['OwnerUserId'] ?? 0) !== $userId) {
            throw new FederationException('Sólo el propietario local puede ordenar réplicas de este recurso.', 403);
        }
        return $resource;
    }

    private function targetIsActive(string $resourceId, string $nodeId): bool
    {
        foreach ($this->catalog->locations($resourceId) as $location) {
            if (hash_equals($nodeId, (string)$location['node_id']) && (string)$location['status'] === 'active') return true;
        }
        return false;
    }

    private function stableOfferId(string $resourceId, string $targetNodeId): string
    {
        $digest = hash_hmac('sha256', 'replica-job:' . $resourceId . ':' . $targetNodeId, $this->identity->payloadKey(), true);
        return 'fro_' . FederationCodec::base64UrlEncode(substr($digest, 0, 18));
    }

    private function replicaS3Key(string $originNodeId, string $resourceId): string
    {
        return 'FederationCloud/Replicas/' . $originNodeId . '/' . $resourceId;
    }

    private function decodeArcadeLink(string $json): array
    {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) throw new FederationException('ArcadeLink de recurso local inválido.', 500);
        return $decoded;
    }

    private function ensureEnabled(): void
    {
        if (!$this->config->enabled()) throw new FederationException('FederationCloud está desactivado en este nodo.', 503);
    }
}
