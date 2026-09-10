<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;

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

    public function createLink(int $userId, int $fileId, string $visibility, string $rights): array
    {
        $this->ensureEnabled();
        $file = $this->resources->requireOwnedFile($userId, $fileId);
        return $this->createLinkForFile($file, $userId, $visibility, $rights);
    }

    public function createLinkByStorageRef(int $userId, string $storageRef, string $visibility, string $rights): array
    {
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
        return $this->createLinkForFile($file, $userId, $visibility, $rights);
    }

    public function inspect(string $raw, int $viewerUserId = 0): array
    {
        $this->ensureEnabled();
        $document = $this->links->parse($raw);
        return [
            'document' => $document,
            'resource' => $this->resolver->resolve($document, $viewerUserId, true),
            'raw' => $this->links->encode($document, false),
        ];
    }

    public function resolveForOrigin(array|string $arcadeLink, int $viewerUserId = 0): array
    {
        $this->ensureEnabled();
        $raw = is_array($arcadeLink) ? $this->links->encode($arcadeLink, false) : $arcadeLink;
        $document = $this->links->parse($raw);
        if (!hash_equals($this->identity->nodeId(), (string)$document['origin_node_id'])) {
            throw new FederationException('Este nodo no es el origen del ArcadeLink.', 409);
        }
        return $this->resolver->resolve($document, $viewerUserId, false);
    }

    public function openLocal(string $raw, int $viewerUserId = 0): array
    {
        $this->ensureEnabled();
        $document = $this->links->parse($raw);
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

    private function createLinkForFile(array $file, int $userId, string $visibility, string $rights): array
    {
        $document = $this->links->create(
            $file,
            $userId,
            $this->resources->contentId($file),
            $this->resources->mediaType($file),
            $visibility,
            $rights
        );
        return [
            'document' => $document,
            'content' => $this->links->encode($document),
            'filename' => $this->links->suggestedFilename($document),
            'file_id' => (int)$file['id_'],
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
