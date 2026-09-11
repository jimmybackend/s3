<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationNodeAdminService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederationNodeDescriptorValidator $validator;
    private FederationNodeRepository $nodes;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->validator = new FederationNodeDescriptorValidator();
        $this->nodes = new FederationNodeRepository($this->app->db());
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

        $this->nodes->assertNodeNameAvailable($name, $nodeId);
        if ($oldName !== null && hash_equals($oldName, $name)) {
            return $this->state() + ['message' => 'El nodo ya usa ese nombre.'];
        }

        $this->identity->renameNodeName($name);

        try {
            $descriptor = $this->validator->validate($this->identity->signedDescriptor($this->config));
            if (!hash_equals((string)$descriptor['node_id'], $nodeId)) {
                throw new FederationException('El Node ID cambió durante el renombre; operación cancelada.', 500);
            }
            $this->nodes->upsertVerified($descriptor);
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
