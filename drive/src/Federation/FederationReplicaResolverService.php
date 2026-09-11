<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;

final class FederationReplicaResolverService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederatedCatalogRepository $catalog;
    private FederationReplicaRepository $replicas;
    private FederationLocationSelector $selector;
    private FederationHttpClient $http;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->catalog = new FederatedCatalogRepository($app->db());
        $this->replicas = new FederationReplicaRepository($app->db());
        $this->selector = new FederationLocationSelector();
        $this->http = new FederationHttpClient();
    }

    /** Devuelve acceso temporal desde el original local o desde una réplica local. */
    public function publicLocation(string $resourceId): array
    {
        $resource = $this->requirePublicCopyable($resourceId);
        $localNodeId = $this->identity->nodeId();

        if (hash_equals($localNodeId, (string)$resource['OriginNodeId'])) {
            $document = $this->decodeArcadeLink((string)($resource['ArcadeLinkJson'] ?? ''));
            $arcade = new ArcadeLinkService($this->config, $this->identity);
            $verified = $arcade->parse(FederationCodec::canonicalJson($document));
            $payload = $arcade->decryptLocalPayload($verified);
            $storageRef = trim((string)($payload['storage_ref'] ?? ''));
            if ($storageRef === '') throw new FederationException('El origen local no conserva referencia física.', 409);
            return [
                'ok' => true,
                'resource_id' => $resourceId,
                'node_id' => $localNodeId,
                'role' => 'origin',
                'access_url' => $this->app->shareObjectStorage()->presignedUrl($storageRef, '+5 minutes'),
                'expires_in' => 300,
            ];
        }

        $object = $this->replicas->object($resourceId);
        if ($object === null || (string)$object['Status'] !== 'active') {
            throw new FederationException('Este nodo no tiene una copia activa del recurso.', 404);
        }
        if (!hash_equals((string)$resource['ContentId'], (string)$object['ContentId'])
            || (int)$resource['SizeBytes'] !== (int)$object['SizeBytes']) {
            throw new FederationException('La réplica local no coincide con el catálogo global.', 409);
        }
        return [
            'ok' => true,
            'resource_id' => $resourceId,
            'node_id' => $localNodeId,
            'role' => (string)$object['Role'],
            'access_url' => $this->app->shareObjectStorage()->presignedUrl((string)$object['S3Key'], '+5 minutes'),
            'expires_in' => 300,
        ];
    }

    /** Elige mirror/provider activo antes que origin para repartir carga. */
    public function openPreferred(string $resourceId): array
    {
        $resource = $this->requirePublicCopyable($resourceId);
        $preferred = $this->selector->preferred($this->catalog->locations($resourceId));
        if ($preferred === null) throw new FederationException('No hay ubicación FederationCloud disponible para este recurso.', 503);

        if (hash_equals($this->identity->nodeId(), (string)$preferred['node_id'])) {
            return $this->publicLocation($resourceId) + ['preferred_location' => $preferred];
        }
        $federationUrl = trim((string)$preferred['federation_url']);
        if ($federationUrl === '') throw new FederationException('La ubicación preferida no publica Federation URL.', 503);
        $result = $this->http->postJson($federationUrl, 'replica-resolve.php', ['resource_id' => $resourceId]);
        $url = trim((string)($result['access_url'] ?? ''));
        $parts = parse_url($url);
        if (empty($result['ok']) || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])) {
            throw new FederationException('La ubicación preferida no devolvió acceso HTTPS válido.', 502);
        }
        return $result + ['preferred_location' => $preferred];
    }

    private function requirePublicCopyable(string $resourceId): array
    {
        if (!preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            throw new FederationException('Resource ID inválido.', 400);
        }
        $resource = $this->catalog->find($resourceId);
        if ($resource === null || (int)($resource['Tombstoned'] ?? 0) !== 0) {
            throw new FederationException('Recurso global no encontrado.', 404);
        }
        if ((string)$resource['Visibility'] !== 'PUBLIC' || (string)$resource['Rights'] !== 'copy_allowed') {
            throw new FederationException('El failover directo sólo está disponible para recursos PUBLIC + copy_allowed.', 403);
        }
        return $resource;
    }

    private function decodeArcadeLink(string $json): array
    {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) throw new FederationException('ArcadeLink del recurso inválido.', 500);
        return $decoded;
    }
}
