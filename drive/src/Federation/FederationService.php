<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use Throwable;

final class FederationService
{
    private FederationConfig $config;
    private NodeIdentityService $identity;
    private FederatedResourceRepository $resources;
    private ArcadeLinkService $links;
    private FederationResolverService $resolver;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationConfig::fromEnvironment();
        $this->identity = new NodeIdentityService($this->config->identityPath());
        $this->resources = new FederatedResourceRepository($this->app->db());
        $this->links = new ArcadeLinkService($this->config, $this->identity);
        $this->resolver = new FederationResolverService(
            $this->config,
            $this->identity,
            $this->links,
            $this->resources,
            new FederationHttpClient()
        );
    }

    public function ensureEnabled(): void
    {
        if (!$this->config->enabled()) {
            throw new FederationException('FederationCloud está desactivado en este nodo.', 503);
        }
    }

    public function nodeDescriptor(): array
    {
        $this->ensureEnabled();
        return $this->identity->signedDescriptor($this->config);
    }

    public function createLink(int $userId, int $fileId, string $visibility, string $rights, string $discoveryPolicy = ''): array
    {
        $this->ensureEnabled();
        $file = $this->resources->requireOwnedFile($userId, $fileId);
        return $this->createLinkForFile($file, $userId, $visibility, $rights, $discoveryPolicy);
    }

    public function createLinkByStorageRef(
        int $userId,
        string $storageRef,
        string $visibility,
        string $rights,
        string $discoveryPolicy = ''
    ): array {
        $this->ensureEnabled();
        $storageRef = str_replace('\\', '/', trim($storageRef));
        $storageRef = preg_replace('~/+~', '/', $storageRef) ?? $storageRef;
        $storageRef = ltrim($storageRef, '/');
        if ($storageRef === '') {
            throw new FederationException('Referencia de archivo ausente.', 400);
        }
        $file = $this->resources->findOwnedFileByStorageRef($userId, $storageRef);
        if ($file === null) {
            throw new FederationException('Archivo no encontrado para este usuario.', 404);
        }
        return $this->createLinkForFile($file, $userId, $visibility, $rights, $discoveryPolicy);
    }

    /**
     * Crea un único archivo .arcadelink que representa uno o muchos recursos.
     *
     * @param array<int,string> $storageRefs
     */
    public function createCollectionByStorageRefs(
        int $userId,
        array $storageRefs,
        string $visibility,
        string $rights,
        string $discoveryPolicy = ''
    ): array {
        $this->ensureEnabled();
        $storageRefs = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $storageRefs
        ), static fn(string $value): bool => $value !== '')));

        if ($storageRefs === [] || count($storageRefs) > ArcadeLinkService::MAX_COLLECTION_ITEMS) {
            throw new FederationException(
                'Selecciona entre 1 y ' . ArcadeLinkService::MAX_COLLECTION_ITEMS . ' archivos para el ArcadeLink.',
                400
            );
        }

        if (count($storageRefs) === 1) {
            return $this->createLinkByStorageRef(
                $userId,
                $storageRefs[0],
                $visibility,
                $rights,
                $discoveryPolicy
            ) + ['item_count' => 1, 'collection' => false];
        }

        $documents = [];
        $fileIds = [];
        foreach ($storageRefs as $storageRef) {
            $created = $this->createLinkByStorageRef(
                $userId,
                $storageRef,
                $visibility,
                $rights,
                $discoveryPolicy
            );
            $documents[] = $created['document'];
            $fileIds[] = (int)$created['file_id'];
        }

        $title = 'Compartidos-' . count($documents) . '-archivos';
        $document = $this->links->createCollection($documents, $title);

        return [
            'document' => $document,
            'content' => $this->links->encode($document),
            'filename' => $this->links->suggestedFilename($document),
            'file_ids' => $fileIds,
            'item_count' => count($documents),
            'collection' => true,
        ];
    }

    public function inspect(string $raw, int $viewerUserId = 0): array
    {
        $this->ensureEnabled();
        $document = $this->links->parse($raw);

        if ((int)($document['version'] ?? 0) === 2
            && (string)($document['resource_type'] ?? '') === 'collection') {
            $items = [];
            foreach ($document['items'] as $item) {
                $items[] = [
                    'document' => $item,
                    'resource' => $this->resolver->resolve($item, $viewerUserId, true),
                    'raw' => $this->links->encode($item, false),
                ];
            }

            return [
                'document' => $document,
                'collection' => true,
                'title' => (string)$document['title'],
                'item_count' => count($items),
                'items' => $items,
                'raw' => $this->links->encode($document, false),
            ];
        }

        return [
            'document' => $document,
            'collection' => false,
            'item_count' => 1,
            'resource' => $this->resolver->resolve($document, $viewerUserId, true),
            'raw' => $this->links->encode($document, false),
        ];
    }

    public function resolveForOrigin(array|string $arcadeLink, int $viewerUserId = 0): array
    {
        $this->ensureEnabled();
        $raw = is_array($arcadeLink) ? $this->links->encode($arcadeLink, false) : $arcadeLink;
        $document = $this->links->parse($raw);
        if ((int)($document['version'] ?? 0) !== 1 || (string)($document['resource_type'] ?? '') !== 'file') {
            throw new FederationException('La resolución remota acepta ArcadeLinks de archivo; las colecciones se resuelven elemento por elemento.', 409);
        }
        if (!hash_equals($this->identity->nodeId(), (string)$document['origin_node_id'])) {
            throw new FederationException('Este nodo no es el origen del ArcadeLink.', 409);
        }
        return $this->resolver->resolve($document, $viewerUserId, false);
    }

    public function openLocal(string $raw, int $viewerUserId = 0): array
    {
        $this->ensureEnabled();
        $document = $this->links->parse($raw);
        if ((int)($document['version'] ?? 0) !== 1 || (string)($document['resource_type'] ?? '') !== 'file') {
            throw new FederationException('Selecciona un archivo individual de la colección para abrirlo.', 409);
        }
        $open = $this->resolver->requireOpenableLocal($document, $viewerUserId);
        $file = $open['file'];
        $payload = $open['payload'];
        $key = $this->resources->storageKey($file);
        if ($key === '') throw new FederationException('El recurso local no tiene una key válida.', 500);
        $share = $this->app->shareLinkService()->create(
            (int)$payload['user_id'],
            $key,
            $this->shareType((string)$document['media_type']),
            1
        );
        $url = $this->config->publicUrl() . '/' . ltrim((string)$share['endpoint'], '/')
            . '?t=' . rawurlencode((string)$share['token']);
        return [
            'url' => $url,
            'file_id' => (int)$file['id_'],
            'owner_user_id' => (int)$payload['user_id'],
            'resource_id' => (string)$document['resource_id'],
        ];
    }

    public function config(): FederationConfig
    {
        return $this->config;
    }

    private function createLinkForFile(
        array $file,
        int $userId,
        string $visibility,
        string $rights,
        string $discoveryPolicy = ''
    ): array {
        $document = $this->links->create(
            $file,
            $userId,
            $this->resources->contentId($file),
            $this->resources->mediaType($file),
            $visibility,
            $rights
        );

        $catalog = [
            'published' => false,
            'discovery_policy' => $discoveryPolicy !== ''
                ? strtolower(trim($discoveryPolicy))
                : FederationCatalogService::defaultDiscoveryPolicy((string)$document['visibility']),
        ];
        try {
            $catalog = (new FederationCatalogService($this->app))->publishArcadeLink($document, $userId, $discoveryPolicy);
        } catch (Throwable $e) {
            // El ArcadeLink sigue siendo portable aun si el catálogo global todavía
            // no fue migrado o está temporalmente degradado.
            error_log('[FederationCloud catalog] ' . $e->getMessage());
            $catalog['degraded'] = true;
        }

        return [
            'document' => $document,
            'content' => $this->links->encode($document),
            'filename' => $this->links->suggestedFilename($document),
            'file_id' => (int)$file['id_'],
            'catalog' => $catalog,
        ];
    }

    private function shareType(string $mediaType): string
    {
        if (str_starts_with($mediaType, 'audio/')) return 'audio';
        if (str_starts_with($mediaType, 'video/')) return 'video';
        if (str_starts_with($mediaType, 'image/')) return 'imagen';
        if (str_starts_with($mediaType, 'text/')) return 'texto';
        return 'otro';
    }
}
