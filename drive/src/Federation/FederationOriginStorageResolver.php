<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use JsonException;

/**
 * Resuelve la key S3 canónica del objeto original a partir del ArcadeLink firmado
 * y de FileS3. Nunca usa el storage_ref cifrado directamente como Key.
 */
final class FederationOriginStorageResolver
{
    private ArcadeLinkService $links;
    private FederatedResourceRepository $resources;
    private FederationResolverService $resolver;

    public function __construct(
        DriveApplication $app,
        private FederationConfig $config,
        private NodeIdentityService $identity
    ) {
        $this->links = new ArcadeLinkService($config, $identity);
        $this->resources = new FederatedResourceRepository($app->db());
        $this->resolver = new FederationResolverService(
            $config,
            $identity,
            $this->links,
            $this->resources,
            new FederationHttpClient()
        );
    }

    public function storageKey(array $resource): string
    {
        $localNodeId = $this->identity->nodeId();
        $originNodeId = trim((string)($resource['OriginNodeId'] ?? ''));
        if ($originNodeId === '' || !hash_equals($localNodeId, $originNodeId)) {
            throw new FederationException('El recurso no pertenece al nodo origen local.', 409);
        }

        $document = $this->decodeArcadeLink((string)($resource['ArcadeLinkJson'] ?? ''));
        $verified = $this->links->parse(FederationCodec::canonicalJson($document));

        $documentOrigin = trim((string)($verified['origin_node_id'] ?? ''));
        if ($documentOrigin === '' || !hash_equals($localNodeId, $documentOrigin)) {
            throw new FederationException('El ArcadeLink no corresponde al nodo origen local.', 409);
        }

        $catalogResourceId = trim((string)($resource['ResourceId'] ?? ''));
        $documentResourceId = trim((string)($verified['resource_id'] ?? ''));
        if ($catalogResourceId !== '' && ($documentResourceId === '' || !hash_equals($catalogResourceId, $documentResourceId))) {
            throw new FederationException('El ArcadeLink no coincide con el recurso del catálogo.', 409);
        }

        $open = $this->resolver->requireOpenableLocal($verified, 0);
        $storageKey = $this->resources->storageKey((array)$open['file']);
        $storageKey = ltrim(str_replace('\\', '/', trim($storageKey)), '/');
        if ($storageKey === '') {
            throw new FederationException('El origen local no conserva una key física válida.', 409);
        }

        return $storageKey;
    }

    private function decodeArcadeLink(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('ArcadeLink del recurso inválido.', 500);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new FederationException('ArcadeLink del recurso inválido.', 500);
        }
        return $decoded;
    }
}
