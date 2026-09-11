<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationNodeAdminService
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

    public function state(): array
    {
        $this->ensureEnabled();
        $descriptor = $this->validator->validate($this->identity->signedDescriptor($this->config));

        return [
            'ok' => true,
            'node' => $this->summary($descriptor),
        ];
    }

    public function renameNode(string $requestedName): array
    {
        $this->ensureEnabled();
        $name = NodeIdentityService::normalizeNodeName($requestedName);
        $nodeId = $this->identity->nodeId();
        $oldName = $this->identity->nodeName();
        $isSeed = $this->seeds->isSeed($this->config->federationUrl());

        if ($oldName !== null && hash_equals($oldName, $name)) {
            return $this->state() + ['message' => 'El nodo ya usa ese nombre.'];
        }

        if ($isSeed) {
            $this->nodes->assertNodeNameAvailable($name, $nodeId);
        }

        $this->identity->renameNodeName($name);

        try {
            $descriptor = $this->validator->validate($this->identity->signedDescriptor($this->config));
            if (!hash_equals((string)$descriptor['node_id'], $nodeId)) {
                throw new FederationException('El Node ID cambió durante el renombre; operación cancelada.', 500);
            }

            if ($isSeed) {
                $this->nodes->upsertVerified($descriptor);
            } else {
                $response = $this->http->postJson($this->seeds->primary(), 'register.php', [
                    'descriptor' => $descriptor,
                ]);
                if (($response['ok'] ?? null) !== true) {
                    throw new FederationException('El seed no confirmó el nuevo nombre del nodo.', 502);
                }
            }
        } catch (Throwable $e) {
            if ($oldName !== null && $oldName !== '') {
                try {
                    $this->identity->renameNodeName($oldName);
                } catch (Throwable) {
                    // El error original es más útil; la siguiente lectura detectará la inconsistencia.
                }
            }
            throw $e;
        }

        return [
            'ok' => true,
            'message' => 'Nombre del nodo actualizado sin cambiar su identidad criptográfica.',
            'node' => $this->summary($descriptor),
        ];
    }

    private function summary(array $descriptor): array
    {
        return [
            'node_id' => (string)$descriptor['node_id'],
            'node_name' => is_string($descriptor['node_name'] ?? null) ? (string)$descriptor['node_name'] : null,
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
