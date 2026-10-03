<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;

final class FederationResourceDeliveryService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederationEventStore $events;
    private FederationResourceDeliveryRepository $deliveries;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $catalog = new FederatedCatalogRepository($app->db());
        $this->events = new FederationEventStore($app->db(), $catalog, new FederationEventCodec());
        $this->deliveries = new FederationResourceDeliveryRepository($app->db());
    }

    public function recordCompleted(string $resourceId, array $sources, int $bytesDelivered, bool $parallel): array
    {
        if (!$this->config->enabled()) throw new FederationException('FederationCloud está desactivado en este nodo.', 503);
        if (!preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            throw new FederationException('Resource ID inválido para historial.', 400);
        }
        if ($bytesDelivered < 0 || $bytesDelivered > FederationReplicaDownloader::MAX_BYTES) {
            throw new FederationException('Tamaño de entrega federada inválido.', 400);
        }

        $clean = [];
        foreach ($sources as $source) {
            if (!is_array($source)) continue;
            $nodeId = trim((string)($source['node_id'] ?? ''));
            $role = strtolower(trim((string)($source['role'] ?? '')));
            if (!preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', $nodeId)
                || !in_array($role, ['origin','provider','mirror'], true)) {
                continue;
            }
            $clean[$nodeId] = ['node_id' => $nodeId, 'role' => $role];
        }
        $clean = array_values($clean);
        if ($clean === [] || count($clean) > FederationMultiSourceDownloader::MAX_SOURCES) {
            throw new FederationException('Fuentes inválidas para historial FederationCloud.', 400);
        }

        $deliveryId = 'fdl_' . FederationCodec::base64UrlEncode(random_bytes(18));
        $completedAt = gmdate(DATE_ATOM);
        $payload = [
            'delivery_id' => $deliveryId,
            'resource_id' => $resourceId,
            'request_node_id' => $this->identity->nodeId(),
            'sources' => $clean,
            'source_count' => count($clean),
            'bytes_delivered' => $bytesDelivered,
            'transport' => $parallel && count($clean) > 1 ? 'multisource' : 'single_source_proxy',
            'completed_at' => $completedAt,
        ];
        $event = $this->events->emit($this->identity, 'delivery.record', $deliveryId, $payload);

        return [
            'delivery_id' => $deliveryId,
            'event_id' => (string)$event['event_id'],
            'resource_id' => $resourceId,
            'request_node_id' => $this->identity->nodeId(),
            'sources' => $clean,
            'source_count' => count($clean),
            'bytes_delivered' => $bytesDelivered,
            'transport' => (string)$payload['transport'],
            'completed_at' => $completedAt,
        ];
    }

    public function summary(string $resourceId): array
    {
        return $this->deliveries->summary($resourceId);
    }

    public function history(string $resourceId, int $limit = 100): array
    {
        return $this->deliveries->history($resourceId, $limit);
    }
}
