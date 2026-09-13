<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;

final class FederationProviderAuthorizationService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederationNodeDescriptorValidator $validator;
    private FederationNodeRepository $nodes;
    private FederationProviderAuthorizationRepository $authorizations;
    private FederationHttpClient $http;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->validator = new FederationNodeDescriptorValidator();
        $this->nodes = new FederationNodeRepository($this->app->db());
        $this->authorizations = new FederationProviderAuthorizationRepository($this->app->db());
        $this->http = new FederationHttpClient();
    }

    public function receiveRequest(
        string $originNodeId,
        array $providerDescriptor,
        string $role,
        string $scope
    ): array {
        $this->ensureEnabled();
        $role = FederationProviderGrant::normalizeRole($role);
        $scope = FederationProviderGrant::normalizeScope($scope);

        $local = $this->validator->validate($this->identity->signedDescriptor($this->config));
        if (!hash_equals((string)$local['node_id'], trim($originNodeId))) {
            throw new FederationException('La solicitud no está dirigida a este nodo origen.', 409);
        }

        $candidate = $this->validator->validate($providerDescriptor);
        if (hash_equals((string)$local['node_id'], (string)$candidate['node_id'])) {
            throw new FederationException('Un nodo no puede solicitar ser proveedor de sí mismo.', 409);
        }

        $live = $this->validator->validate(
            $this->http->getJson((string)$candidate['federation_url'], 'node.php')
        );
        foreach (['node_id', 'public_key', 'public_url', 'federation_url'] as $field) {
            if (!hash_equals((string)$candidate[$field], (string)$live[$field])) {
                throw new FederationException('El nodo proveedor anunciado no coincide con su descriptor en vivo.', 409);
            }
        }
        $candidateName = (string)($candidate['node_name'] ?? '');
        $liveName = (string)($live['node_name'] ?? '');
        if (!hash_equals($candidateName, $liveName)) {
            throw new FederationException('El nombre firmado del proveedor no coincide con el nodo en vivo.', 409);
        }

        $this->nodes->upsertVerified($live);
        $status = $this->authorizations->request(
            (string)$local['node_id'],
            (string)$live['node_id'],
            $role,
            $scope
        );

        return [
            'ok' => true,
            'status' => $status,
            'origin_node_id' => (string)$local['node_id'],
            'provider_node' => $this->nodeSummary($live),
            'role' => $role,
            'scope' => $scope,
            'relationship' => 'shared_backend',
            'requires_superadmin' => true,
            'message' => $status === 'active'
                ? 'La copia ya estaba autorizada para servir recursos del nodo origen.'
                : 'Solicitud de backend compartido recibida. Requiere aprobación de un superusuario en el nodo origen.',
        ];
    }

    public function requestFromLocalNode(
        string $originFederationUrl,
        string $role = 'provider',
        string $scope = 'all_allowed_resources'
    ): array {
        $this->ensureEnabled();
        $role = FederationProviderGrant::normalizeRole($role);
        $scope = FederationProviderGrant::normalizeScope($scope);
        $local = $this->validator->validate($this->identity->signedDescriptor($this->config));
        $origin = $this->validator->validate($this->http->getJson($originFederationUrl, 'node.php'));

        if (hash_equals((string)$local['node_id'], (string)$origin['node_id'])) {
            throw new FederationException('El nodo origen y la copia son la misma identidad.', 409);
        }

        return $this->http->postJson($originFederationUrl, 'provider-request.php', [
            'origin_node_id' => (string)$origin['node_id'],
            'provider_descriptor' => $local,
            'relationship' => 'shared_backend',
            'role' => $role,
            'scope' => $scope,
        ]);
    }

    public function adminState(): array
    {
        $this->ensureEnabled();
        $originNodeId = $this->identity->nodeId();
        return [
            'ok' => true,
            'origin_node_id' => $originNodeId,
            'origin_node_name' => $this->identity->nodeName(),
            'pending' => array_map([$this, 'adminRow'], $this->authorizations->pendingForOrigin($originNodeId)),
            'active' => array_map([$this, 'adminRow'], $this->authorizations->activeForOrigin($originNodeId)),
        ];
    }

    public function decide(string $providerNodeId, string $decision): array
    {
        $this->ensureEnabled();
        $originNodeId = $this->identity->nodeId();
        $providerNodeId = trim($providerNodeId);
        if (!preg_match('/\Aacn_[A-Za-z0-9_-]{20,64}\z/', $providerNodeId)) {
            throw new FederationException('Provider Node ID inválido.', 400);
        }

        $decision = strtolower(trim($decision));
        if ($decision === 'reject') {
            $this->authorizations->reject($originNodeId, $providerNodeId);
            return ['ok' => true, 'status' => 'revoked', 'provider_node_id' => $providerNodeId];
        }
        if ($decision === 'revoke') {
            $this->authorizations->revoke($originNodeId, $providerNodeId);
            return ['ok' => true, 'status' => 'revoked', 'provider_node_id' => $providerNodeId];
        }
        if ($decision !== 'approve') {
            throw new FederationException('Decisión de proveedor inválida.', 400);
        }

        $row = $this->authorizations->find($originNodeId, $providerNodeId);
        if ($row === null || (string)$row['Status'] !== 'pending') {
            throw new FederationException('La solicitud de proveedor no está pendiente.', 409);
        }
        $role = FederationProviderGrant::normalizeRole((string)$row['Role']);
        $scope = FederationProviderGrant::normalizeScope((string)$row['Scope']);
        $grant = FederationProviderGrant::sign($this->identity, $providerNodeId, $role, $scope);
        $signature = (string)$grant['signature']['value'];
        $this->authorizations->approve($originNodeId, $providerNodeId, $role, $scope, $signature);

        return [
            'ok' => true,
            'status' => 'active',
            'provider_node_id' => $providerNodeId,
            'grant' => $grant,
        ];
    }

    public function publicProviders(): array
    {
        $this->ensureEnabled();
        $originNodeId = $this->identity->nodeId();
        $rows = $this->authorizations->activeForOrigin($originNodeId);
        $providers = [];
        foreach ($rows as $row) {
            $grant = FederationProviderGrant::payload(
                $originNodeId,
                (string)$row['ProviderNodeId'],
                (string)$row['Role'],
                (string)$row['Scope']
            );
            $grant['signature'] = [
                'alg' => 'Ed25519',
                'key_id' => $originNodeId,
                'value' => (string)$row['OriginSignature'],
            ];
            $providers[] = [
                'node' => [
                    'node_id' => (string)$row['ProviderNodeId'],
                    'node_name' => is_string($row['NodeName'] ?? null) && $row['NodeName'] !== '' ? (string)$row['NodeName'] : null,
                    'public_url' => (string)$row['PublicUrl'],
                    'federation_url' => (string)$row['FederationUrl'],
                ],
                'role' => (string)$row['Role'],
                'scope' => (string)$row['Scope'],
                'authorized_at' => $row['AuthorizedAt'] !== null ? (string)$row['AuthorizedAt'] : null,
                'last_seen' => $row['LastSeen'] !== null ? (string)$row['LastSeen'] : null,
                'grant' => $grant,
            ];
        }

        return [
            'ok' => true,
            'origin_node_id' => $originNodeId,
            'origin_node_name' => $this->identity->nodeName(),
            'providers' => $providers,
            'provider_count' => count($providers),
        ];
    }

    private function adminRow(array $row): array
    {
        return [
            'provider_node_id' => (string)$row['ProviderNodeId'],
            'provider_node_name' => is_string($row['NodeName'] ?? null) && $row['NodeName'] !== '' ? (string)$row['NodeName'] : null,
            'public_url' => (string)$row['PublicUrl'],
            'federation_url' => (string)$row['FederationUrl'],
            'role' => (string)$row['Role'],
            'scope' => (string)$row['Scope'],
            'status' => (string)$row['Status'],
            'requested_at' => (string)$row['RequestedAt'],
            'authorized_at' => $row['AuthorizedAt'] !== null ? (string)$row['AuthorizedAt'] : null,
            'last_seen' => $row['LastSeen'] !== null ? (string)$row['LastSeen'] : null,
        ];
    }

    private function nodeSummary(array $descriptor): array
    {
        return [
            'node_id' => (string)$descriptor['node_id'],
            'node_name' => is_string($descriptor['node_name'] ?? null) && $descriptor['node_name'] !== '' ? (string)$descriptor['node_name'] : null,
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
