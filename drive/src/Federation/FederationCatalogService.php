<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;

final class FederationCatalogService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederatedCatalogRepository $catalog;
    private FederationEventStore $events;
    private FederationLocationSelector $locations;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->catalog = new FederatedCatalogRepository($this->app->db());
        $this->events = new FederationEventStore(
            $this->app->db(),
            $this->catalog,
            new FederationEventCodec()
        );
        $this->locations = new FederationLocationSelector();
    }

    public function announceNodeIfChanged(): ?array
    {
        $this->ensureEnabled();
        $descriptor = $this->identity->signedDescriptor($this->config);
        $payload = [
            'node_id' => (string)$descriptor['node_id'],
            'node_name' => is_string($descriptor['node_name'] ?? null) ? (string)$descriptor['node_name'] : '',
            'public_url' => (string)$descriptor['public_url'],
            'federation_url' => (string)$descriptor['federation_url'],
        ];
        $hash = hash('sha256', FederationCodec::canonicalJson($payload));
        $previous = $this->events->latestPayloadHash($this->identity->nodeId(), 'node.upsert', $this->identity->nodeId());
        if ($previous !== null && hash_equals($previous, $hash)) return null;
        return $this->events->emit($this->identity, 'node.upsert', $this->identity->nodeId(), $payload);
    }

    public function publishArcadeLink(array $document, int $ownerUserId, string $discoveryPolicy = ''): array
    {
        $this->ensureEnabled();
        $originNodeId = (string)($document['origin_node_id'] ?? '');
        $resourceId = (string)($document['resource_id'] ?? '');
        if (!hash_equals($this->identity->nodeId(), $originNodeId)) {
            throw new FederationException('Sólo el nodo origen puede publicar el recurso en el catálogo global.', 409);
        }
        if ($resourceId === '' || $ownerUserId <= 0) throw new FederationException('Recurso local inválido para publicación.', 400);

        $policy = strtolower(trim($discoveryPolicy));
        if ($policy === '') $policy = self::defaultDiscoveryPolicy((string)($document['visibility'] ?? 'UNLISTED'));
        if (!in_array($policy, ['local_only','public_metadata','requestable_metadata'], true)) {
            throw new FederationException('Política de descubrimiento federado inválida.', 400);
        }

        if ($policy === 'local_only') {
            $existing = $this->catalog->find($resourceId);
            if ($existing !== null && hash_equals((string)$existing['OriginNodeId'], $originNodeId)) {
                $this->events->emit($this->identity, 'location.tombstone', $resourceId . '@' . $originNodeId, [
                    'resource_id' => $resourceId,
                    'node_id' => $originNodeId,
                ]);
                $this->events->emit($this->identity, 'resource.tombstone', $resourceId, [
                    'resource_id' => $resourceId,
                    'origin_node_id' => $originNodeId,
                ]);
            }
            return ['published' => false, 'discovery_policy' => 'local_only'];
        }

        $visibility = strtoupper((string)($document['visibility'] ?? 'UNLISTED'));
        if ($policy === 'public_metadata' && $visibility !== 'PUBLIC') {
            throw new FederationException('public_metadata requiere un ArcadeLink PUBLIC.', 409);
        }

        $resourcePayload = [
            'resource_id' => $resourceId,
            'origin_node_id' => $originNodeId,
            'resource_type' => (string)($document['resource_type'] ?? 'file'),
            'title' => (string)($document['title'] ?? 'Recurso'),
            'media_type' => (string)($document['media_type'] ?? 'application/octet-stream'),
            'size_bytes' => max(0, (int)($document['size_bytes'] ?? 0)),
            'content_id' => is_string($document['content_id'] ?? null) ? (string)$document['content_id'] : null,
            'visibility' => $visibility,
            'discovery_policy' => $policy,
            'rights' => (string)($document['rights'] ?? 'link_only'),
            'origin_url' => (string)($document['origin'] ?? $this->config->publicUrl()),
            'federation_url' => (string)($document['federation_url'] ?? $this->config->federationUrl()),
            'arcadelink' => $document,
        ];
        $resourceEvent = $this->events->emit($this->identity, 'resource.upsert', $resourceId, $resourcePayload);
        $this->catalog->setLocalOwner($resourceId, $originNodeId, $ownerUserId);
        $locationEvent = $this->events->emit($this->identity, 'location.upsert', $resourceId . '@' . $originNodeId, [
            'resource_id' => $resourceId,
            'node_id' => $originNodeId,
            'role' => 'origin',
            'status' => 'active',
            'federation_url' => $this->config->federationUrl(),
        ]);

        return [
            'published' => true,
            'resource_id' => $resourceId,
            'discovery_policy' => $policy,
            'resource_event_id' => (string)$resourceEvent['event_id'],
            'location_event_id' => (string)$locationEvent['event_id'],
        ];
    }

    public function search(string $query, int $limit = 20): array
    {
        $this->ensureEnabled();
        $query = trim($query);
        if (strlen($query) < 2 || strlen($query) > 160) throw new FederationException('La búsqueda global debe tener entre 2 y 160 caracteres.', 400);
        $rows = $this->catalog->search($query, $limit);
        foreach ($rows as &$row) {
            $row['preferred_location'] = $this->locations->preferred(is_array($row['locations'] ?? null) ? $row['locations'] : []);
        }
        unset($row);
        return $rows;
    }

    public function resource(string $resourceId): ?array
    {
        $this->ensureEnabled();
        $resource = $this->catalog->find($resourceId);
        if ($resource === null) return null;
        $locations = $this->catalog->locations($resourceId);
        return [
            'resource_id' => (string)$resource['ResourceId'],
            'origin_node_id' => (string)$resource['OriginNodeId'],
            'title' => (string)$resource['Title'],
            'media_type' => (string)$resource['MediaType'],
            'size_bytes' => (int)$resource['SizeBytes'],
            'content_id' => is_string($resource['ContentId'] ?? null) && $resource['ContentId'] !== '' ? (string)$resource['ContentId'] : null,
            'visibility' => (string)$resource['Visibility'],
            'discovery_policy' => (string)$resource['DiscoveryPolicy'],
            'rights' => (string)$resource['Rights'],
            'locations' => $locations,
            'preferred_location' => $this->locations->preferred($locations),
            'arcadelink' => $this->decodeArcadeLink($resource['ArcadeLinkJson'] ?? null),
        ];
    }

    public function pull(array $remoteClock, int $limit = 25): array
    {
        $this->ensureEnabled();
        $this->announceNodeIfChanged();
        return [
            'ok' => true,
            'node_id' => $this->identity->nodeId(),
            'clock' => $this->events->clock(),
            'events' => $this->events->exportMissing($remoteClock, $limit),
        ];
    }

    public function push(array $events): array
    {
        $this->ensureEnabled();
        $result = $this->events->ingestMany($events);
        return ['ok' => true] + $result;
    }

    public function status(): array
    {
        $this->ensureEnabled();
        return ['ok' => true, 'node_id' => $this->identity->nodeId()] + $this->events->status();
    }

    public function eventStore(): FederationEventStore
    {
        return $this->events;
    }

    public static function defaultDiscoveryPolicy(string $visibility): string
    {
        return strtoupper(trim($visibility)) === 'PUBLIC' ? 'public_metadata' : 'local_only';
    }

    private function decodeArcadeLink(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    private function ensureEnabled(): void
    {
        if (!$this->config->enabled()) throw new FederationException('FederationCloud está desactivado en este nodo.', 503);
    }
}
