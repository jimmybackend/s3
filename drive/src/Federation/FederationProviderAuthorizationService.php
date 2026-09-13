<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;

final class FederationProviderAuthorizationService
{
    private const AVAILABILITY_WINDOW_SECONDS = 900;

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

        $live = $this->verifiedLiveDescriptor($candidate);
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
            'requires_superadmin' => $status !== 'active',
            'available' => $status === 'active',
            'message' => $status === 'active'
                ? 'La copia ya estaba autorizada; su presencia fue actualizada sin crear una nueva Solicitud.'
                : 'Solicitud de backend compartido recibida. Requiere aprobación de un superusuario en el nodo origen.',
        ];
    }

    /**
     * Puerta rápida para una copia que YA fue autorizada. No concede permisos,
     * no cambia role/scope y nunca crea una autorización nueva.
     */
    public function receivePresence(string $originNodeId, array $providerDescriptor): array
    {
        $this->ensureEnabled();
        $local = $this->validator->validate($this->identity->signedDescriptor($this->config));
        $originNodeId = trim($originNodeId);
        if (!hash_equals((string)$local['node_id'], $originNodeId)) {
            throw new FederationException('La presencia de réplica no está dirigida a este nodo origen.', 409);
        }

        $candidate = $this->validator->validate($providerDescriptor);
        $providerNodeId = (string)$candidate['node_id'];
        if (hash_equals($originNodeId, $providerNodeId)) {
            throw new FederationException('Una copia FederationCloud debe usar una identidad distinta.', 409);
        }

        $authorization = $this->authorizations->find($originNodeId, $providerNodeId);
        if ($authorization === null) {
            return [
                'ok' => true,
                'status' => 'authorization_required',
                'available' => false,
                'requires_superadmin' => true,
                'origin_node_id' => $originNodeId,
                'provider_node_id' => $providerNodeId,
                'message' => 'No existe autorización previa; la copia debe entrar una vez por Aduana y Solicitudes.',
            ];
        }

        $status = (string)$authorization['Status'];
        if ($status !== 'active') {
            return [
                'ok' => true,
                'status' => $status,
                'available' => false,
                'requires_superadmin' => true,
                'origin_node_id' => $originNodeId,
                'provider_node_id' => $providerNodeId,
                'message' => $status === 'pending'
                    ? 'La autorización de la copia continúa pendiente.'
                    : 'La relación no está activa y no puede reactivarse automáticamente.',
            ];
        }

        $live = $this->verifiedLiveDescriptor($candidate);
        $this->nodes->upsertVerified($live);
        $this->authorizations->touchActive($originNodeId, $providerNodeId);
        $authorization['LastSeen'] = gmdate('Y-m-d H:i:s');

        return [
            'ok' => true,
            'status' => 'active',
            'available' => true,
            'requires_superadmin' => false,
            'origin_node_id' => $originNodeId,
            'provider_node' => $this->nodeSummary($live),
            'role' => (string)$authorization['Role'],
            'scope' => (string)$authorization['Scope'],
            'grant' => $this->grantFromRow($authorization),
            'message' => 'Réplica autorizada reactivada; endpoint y LastSeen actualizados.',
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
                'available' => $this->isAvailable($row['LastSeen'] ?? null),
                'grant' => $this->grantFromRow($row),
            ];
        }

        return [
            'ok' => true,
            'origin_node_id' => $originNodeId,
            'origin_node_name' => $this->identity->nodeName(),
            'availability_window_seconds' => self::AVAILABILITY_WINDOW_SECONDS,
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
            'available' => $this->isAvailable($row['LastSeen'] ?? null),
            'requested_at' => (string)$row['RequestedAt'],
            'authorized_at' => $row['AuthorizedAt'] !== null ? (string)$row['AuthorizedAt'] : null,
            'last_seen' => $row['LastSeen'] !== null ? (string)$row['LastSeen'] : null,
        ];
    }

    private function verifiedLiveDescriptor(array $candidate): array
    {
        $live = $this->validator->validate(
            $this->http->getJson((string)$candidate['federation_url'], 'node.php')
        );
        foreach (['node_id', 'public_key', 'public_url', 'federation_url'] as $field) {
            if (!hash_equals((string)$candidate[$field], (string)$live[$field])) {
                throw new FederationException('El nodo proveedor anunciado no coincide con su descriptor HTTPS en vivo.', 409);
            }
        }
        $candidateName = (string)($candidate['node_name'] ?? '');
        $liveName = (string)($live['node_name'] ?? '');
        if (!hash_equals($candidateName, $liveName)) {
            throw new FederationException('El nombre firmado del proveedor no coincide con el nodo en vivo.', 409);
        }
        return $live;
    }

    private function grantFromRow(array $row): array
    {
        $grant = FederationProviderGrant::payload(
            (string)$row['OriginNodeId'],
            (string)$row['ProviderNodeId'],
            (string)$row['Role'],
            (string)$row['Scope']
        );
        $grant['signature'] = [
            'alg' => 'Ed25519',
            'key_id' => (string)$row['OriginNodeId'],
            'value' => (string)$row['OriginSignature'],
        ];
        return $grant;
    }

    private function isAvailable(mixed $lastSeen): bool
    {
        if (!is_string($lastSeen) || trim($lastSeen) === '') return false;
        $timestamp = strtotime($lastSeen . ' UTC');
        if ($timestamp === false) return false;
        return (time() - $timestamp) <= self::AVAILABILITY_WINDOW_SECONDS;
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
