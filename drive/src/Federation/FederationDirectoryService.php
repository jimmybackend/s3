<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationDirectoryService
{
    private FederationConfig $config;
    private FederationSeedConfig $seeds;
    private NodeIdentityService $identity;
    private FederationNodeDescriptorValidator $validator;
    private FederationNodeRepository $nodes;
    private FederationHttpClient $http;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->seeds = FederationSeedConfig::fromProjectConfig();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->validator = new FederationNodeDescriptorValidator();
        $this->nodes = new FederationNodeRepository($this->app->db());
        $this->http = new FederationHttpClient();
    }

    public function directory(): array
    {
        $this->ensureEnabled();
        $local = $this->validator->validate($this->identity->signedDescriptor($this->config));

        if ($this->seeds->isSeed($this->config->federationUrl())) {
            $this->nodes->upsertVerified($local);
            return $this->seedDirectoryPayload($local);
        }

        try {
            $response = $this->http->postJson($this->seeds->primary(), 'register.php', [
                'descriptor' => $local,
            ]);
            if (($response['ok'] ?? null) !== true
                || !isset($response['connected_nodes'], $response['nodes'])
                || !is_array($response['nodes'])) {
                throw new FederationException('El seed devolvió un directorio FederationCloud inválido.', 502);
            }

            return [
                'ok' => true,
                'seed_node_id' => is_string($response['seed_node_id'] ?? null) ? $response['seed_node_id'] : null,
                'local_node' => $this->publicSummary($local),
                'connected_nodes' => max(1, (int)$response['connected_nodes']),
                'nodes' => array_slice($response['nodes'], 0, 100),
                'active_window_minutes' => FederationNodeRepository::ACTIVE_WINDOW_MINUTES,
                'degraded' => false,
            ];
        } catch (Throwable) {
            return [
                'ok' => true,
                'seed_node_id' => null,
                'local_node' => $this->publicSummary($local),
                'connected_nodes' => 1,
                'nodes' => [$this->publicSummary($local)],
                'active_window_minutes' => FederationNodeRepository::ACTIVE_WINDOW_MINUTES,
                'degraded' => true,
            ];
        }
    }

    public function registerRemote(array $submitted): array
    {
        $this->ensureEnabled();
        if (!$this->seeds->isSeed($this->config->federationUrl())) {
            throw new FederationException('Este nodo no es un seed FederationCloud.', 403);
        }

        $local = $this->validator->validate($this->identity->signedDescriptor($this->config));
        $this->nodes->upsertVerified($local);

        $candidate = $this->validator->validate($submitted);
        if (hash_equals((string)$local['node_id'], (string)$candidate['node_id'])) {
            return $this->seedDirectoryPayload($local);
        }

        $live = $this->validator->validate(
            $this->http->getJson((string)$candidate['federation_url'], 'node.php')
        );

        foreach (['node_id', 'public_key', 'public_url', 'federation_url'] as $field) {
            if (!hash_equals((string)$candidate[$field], (string)$live[$field])) {
                throw new FederationException('El descriptor anunciado no coincide con el nodo remoto verificado.', 409);
            }
        }
        $candidateName = is_string($candidate['node_name'] ?? null) ? (string)$candidate['node_name'] : '';
        $liveName = is_string($live['node_name'] ?? null) ? (string)$live['node_name'] : '';
        if (!hash_equals($candidateName, $liveName)) {
            throw new FederationException('El nombre anunciado no coincide con el nodo remoto verificado.', 409);
        }

        $this->nodes->upsertVerified($live);
        return $this->seedDirectoryPayload($local);
    }

    private function seedDirectoryPayload(array $local): array
    {
        $nodes = $this->nodes->activeDirectory();
        return [
            'ok' => true,
            'seed_node_id' => (string)$local['node_id'],
            'local_node' => $this->publicSummary($local),
            'connected_nodes' => count($nodes),
            'nodes' => $nodes,
            'active_window_minutes' => FederationNodeRepository::ACTIVE_WINDOW_MINUTES,
            'degraded' => false,
        ];
    }

    private function publicSummary(array $descriptor): array
    {
        return [
            'node_id' => (string)$descriptor['node_id'],
            'node_name' => is_string($descriptor['node_name'] ?? null) && $descriptor['node_name'] !== ''
                ? (string)$descriptor['node_name']
                : null,
            'public_url' => (string)$descriptor['public_url'],
            'federation_url' => (string)$descriptor['federation_url'],
        ];
    }

    private function ensureEnabled(): void
    {
        if (!$this->config->enabled()) {
            throw new FederationException('FederationCloud está desactivado en este nodo.', 503);
        }
    }
}
