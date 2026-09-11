<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationGossipService
{
    private FederationCatalogService $catalog;
    private FederationEventStore $events;
    private FederationPeerSyncRepository $peers;
    private FederationHttpClient $http;
    private FederationSyncConfig $syncConfig;
    private string $localNodeId;

    public function __construct(private DriveApplication $app)
    {
        $config = FederationConfig::fromEnvironment();
        if (!$config->enabled()) throw new FederationException('FederationCloud está desactivado en este nodo.', 503);
        $identity = new NodeIdentityService($config->identityPath());
        $this->localNodeId = $identity->nodeId();
        $this->catalog = new FederationCatalogService($app);
        $this->events = $this->catalog->eventStore();
        $this->peers = new FederationPeerSyncRepository($app->db());
        $this->http = new FederationHttpClient();
        $this->syncConfig = FederationSyncConfig::fromEnvironment();
    }

    public function syncOnce(): array
    {
        $this->catalog->announceNodeIfChanged();
        $candidates = $this->peers->candidates($this->localNodeId, $this->syncConfig->scanLimit());
        if (count($candidates) > 1) shuffle($candidates);
        $selected = array_slice($candidates, 0, $this->syncConfig->peersPerRun());

        $results = [];
        $totalPulled = 0;
        $totalPushed = 0;
        foreach ($selected as $peer) {
            $peerNodeId = (string)$peer['node_id'];
            $peerUrl = (string)$peer['federation_url'];
            $pulled = 0;
            $pushed = 0;
            try {
                $pull = $this->http->postJson($peerUrl, 'sync-pull.php', [
                    'clock' => $this->events->clock(),
                    'limit' => $this->syncConfig->batchSize(),
                ]);
                if (($pull['ok'] ?? null) !== true || !is_array($pull['events'] ?? null) || !is_array($pull['clock'] ?? null)) {
                    throw new FederationException('Peer devolvió un bloque de sincronización inválido.', 502);
                }
                if (is_string($pull['node_id'] ?? null) && $pull['node_id'] !== ''
                    && !hash_equals($peerNodeId, (string)$pull['node_id'])) {
                    throw new FederationException('La respuesta gossip no pertenece al peer esperado.', 409);
                }
                if (count($pull['events']) > $this->syncConfig->batchSize()) {
                    throw new FederationException('Peer excedió el tamaño de bloque acordado.', 502);
                }
                $ingested = $this->events->ingestMany($pull['events']);
                $pulled = (int)$ingested['inserted'];

                $missingAtPeer = $this->events->exportMissing($pull['clock'], $this->syncConfig->batchSize());
                if ($missingAtPeer !== []) {
                    $push = $this->http->postJson($peerUrl, 'sync-push.php', ['events' => $missingAtPeer]);
                    if (($push['ok'] ?? null) !== true) throw new FederationException('Peer rechazó el bloque de eventos saliente.', 502);
                    $pushed = count($missingAtPeer);
                }

                $this->peers->success($peerNodeId, $pulled, $pushed);
                $totalPulled += $pulled;
                $totalPushed += $pushed;
                $results[] = [
                    'peer_node_id' => $peerNodeId,
                    'ok' => true,
                    'pulled' => $pulled,
                    'pushed' => $pushed,
                ];
            } catch (Throwable $e) {
                $this->peers->failure($peerNodeId, $e->getMessage());
                $results[] = [
                    'peer_node_id' => $peerNodeId,
                    'ok' => false,
                    'pulled' => $pulled,
                    'pushed' => $pushed,
                    'error' => $e instanceof FederationException ? $e->getMessage() : 'Error de sincronización con peer.',
                ];
            }
        }

        return [
            'ok' => true,
            'local_node_id' => $this->localNodeId,
            'selected_peers' => count($selected),
            'pulled_events' => $totalPulled,
            'pushed_events' => $totalPushed,
            'batch_size' => $this->syncConfig->batchSize(),
            'clock' => $this->events->clock(),
            'peers' => $results,
        ];
    }

    public function status(): array
    {
        return [
            'ok' => true,
            'local_node_id' => $this->localNodeId,
            'catalog' => $this->catalog->status(),
            'peers' => $this->peers->state(),
            'config' => [
                'batch_size' => $this->syncConfig->batchSize(),
                'peers_per_run' => $this->syncConfig->peersPerRun(),
                'scan_limit' => $this->syncConfig->scanLimit(),
            ],
        ];
    }
}
