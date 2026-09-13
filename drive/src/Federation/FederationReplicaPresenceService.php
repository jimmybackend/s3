<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;

final class FederationReplicaPresenceService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederationNodeDescriptorValidator $validator;
    private FederationHttpClient $http;
    private FederationNodeRepository $nodes;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->validator = new FederationNodeDescriptorValidator();
        $this->http = new FederationHttpClient();
        $this->nodes = new FederationNodeRepository($this->app->db());
    }

    /**
     * Se ejecuta únicamente en una copia que configure
     * ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL.
     *
     * Una autorización activa nunca se recrea: la copia sólo anuncia que volvió
     * a estar disponible. Si todavía no existe relación, se crea la solicitud
     * inicial por Aduana para que el superadmin la decida una sola vez.
     */
    public function announce(): array
    {
        if (!$this->config->enabled()) {
            return ['configured' => false, 'status' => 'disabled'];
        }
        $originUrl = $this->config->replicaOriginUrl();
        if ($originUrl === null) {
            return ['configured' => false, 'status' => 'not_replica'];
        }

        $local = $this->validator->validate($this->identity->signedDescriptor($this->config));
        $origin = $this->validator->validate($this->http->getJson($originUrl, 'node.php'));
        if (hash_equals((string)$local['node_id'], (string)$origin['node_id'])) {
            throw new FederationException('La réplica y el nodo origen no pueden compartir la misma identidad FederationCloud.', 409);
        }

        // El origen pasa a ser peer conocido localmente. Así el gossip de esta
        // copia puede empujar su propio node.upsert firmado al origen.
        $this->nodes->upsertVerified($origin);

        $presence = $this->http->postJson($originUrl, 'provider-presence.php', [
            'origin_node_id' => (string)$origin['node_id'],
            'provider_descriptor' => $local,
            'relationship' => 'shared_backend',
        ]);
        $status = strtolower(trim((string)($presence['status'] ?? '')));

        if ($status === 'active') {
            $grant = $presence['grant'] ?? null;
            if (!is_array($grant)
                || !FederationProviderGrant::verify($grant, (string)$origin['public_key'])
                || !hash_equals((string)$origin['node_id'], (string)($grant['origin_node_id'] ?? ''))
                || !hash_equals((string)$local['node_id'], (string)($grant['provider_node_id'] ?? ''))) {
                throw new FederationException('El origen no devolvió una autorización firmada válida para esta réplica.', 409);
            }
            return [
                'configured' => true,
                'status' => 'active',
                'available' => true,
                'origin_node_id' => (string)$origin['node_id'],
                'provider_node_id' => (string)$local['node_id'],
                'origin_federation_url' => $originUrl,
                'role' => (string)($grant['role'] ?? ''),
                'scope' => (string)($grant['scope'] ?? ''),
                'authorization_requested' => false,
                'message' => 'Réplica autorizada reactivada sin crear una nueva Solicitud.',
            ];
        }

        if (in_array($status, ['pending', 'blocked', 'revoked'], true)) {
            return [
                'configured' => true,
                'status' => $status,
                'available' => false,
                'origin_node_id' => (string)$origin['node_id'],
                'provider_node_id' => (string)$local['node_id'],
                'origin_federation_url' => $originUrl,
                'authorization_requested' => false,
                'requires_superadmin' => true,
                'message' => $status === 'pending'
                    ? 'La autorización de esta réplica ya está pendiente en Solicitudes.'
                    : 'La relación no puede reactivarse automáticamente; requiere decisión del superadmin.',
            ];
        }

        if ($status !== 'authorization_required') {
            throw new FederationException('El nodo origen devolvió un estado de réplica desconocido.', 502);
        }

        $role = FederationProviderGrant::normalizeRole(
            (string)(getenv('ARCADECLOUD_FEDERATION_REPLICA_ROLE') ?: 'mirror')
        );
        $scope = FederationProviderGrant::normalizeScope(
            (string)(getenv('ARCADECLOUD_FEDERATION_REPLICA_SCOPE') ?: 'all_allowed_resources')
        );
        $request = $this->http->postJson($originUrl, 'provider-request.php', [
            'origin_node_id' => (string)$origin['node_id'],
            'provider_descriptor' => $local,
            'relationship' => 'shared_backend',
            'role' => $role,
            'scope' => $scope,
        ]);

        return [
            'configured' => true,
            'status' => (string)($request['queue_status'] ?? $request['status'] ?? 'pending'),
            'available' => false,
            'origin_node_id' => (string)$origin['node_id'],
            'provider_node_id' => (string)$local['node_id'],
            'origin_federation_url' => $originUrl,
            'authorization_requested' => true,
            'requires_superadmin' => true,
            'message' => 'Primera relación de backend compartido enviada a Aduana; espera aprobación del superadmin.',
        ];
    }
}
