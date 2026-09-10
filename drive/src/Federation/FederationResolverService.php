<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationResolverService
{
    public function __construct(
        private FederationConfig $config,
        private NodeIdentityService $identity,
        private ArcadeLinkService $links,
        private FederatedResourceRepository $resources,
        private FederationHttpClient $http
    ) {
    }

    public function resolve(array $document, int $viewerUserId = 0, bool $allowRemote = true): array
    {
        if (hash_equals($this->identity->nodeId(), (string)$document['origin_node_id'])) {
            return $this->resolveLocal($document, $viewerUserId);
        }
        if (!$allowRemote) {
            throw new FederationException('Este nodo no es el origen de ese ArcadeLink.', 409);
        }
        return $this->resolveRemote($document);
    }

    public function requireOpenableLocal(array $document, int $viewerUserId = 0): array
    {
        $resolved = $this->resolveLocal($document, $viewerUserId);
        if (($resolved['status'] ?? '') !== 'available') {
            $status = (string)($resolved['status'] ?? 'unavailable');
            $message = match ($status) {
                'private_auth_required' => 'Debes iniciar sesión como propietario para abrir este recurso PRIVATE.',
                'secure_resource_requires_drive' => 'Este archivo protegido debe abrirse desde el Drive del propietario.',
                'content_changed' => 'El contenido local ya no coincide con el SHA-256 firmado por el ArcadeLink.',
                default => 'El recurso no está disponible en este nodo.',
            };
            throw new FederationException($message, $status === 'private_auth_required' ? 401 : 409);
        }
        $payload = $this->links->decryptLocalPayload($document);
        $file = $this->findPayloadFile($payload);
        if ($file === null) {
            throw new FederationException('Archivo no encontrado para este recurso FederationCloud.', 404);
        }
        return ['payload' => $payload, 'file' => $file, 'resolved' => $resolved];
    }

    public function publicNodeDescriptor(array $descriptor): array
    {
        $signature = $descriptor['signature'] ?? null;
        if (!is_array($signature)
            || ($descriptor['protocol'] ?? null) !== 'arcadecloud-federation'
            || (int)($descriptor['version'] ?? 0) !== 1
            || !is_string($descriptor['node_id'] ?? null)
            || !is_string($descriptor['public_key'] ?? null)
            || !is_string($signature['value'] ?? null)
            || ($signature['alg'] ?? null) !== 'Ed25519') {
            throw new FederationException('Descriptor de nodo remoto inválido.', 502);
        }
        $publicKey = FederationCodec::base64UrlDecode((string)$descriptor['public_key']);
        $expectedNodeId = NodeIdentityService::nodeIdFromPublicKey($publicKey);
        if (!hash_equals($expectedNodeId, (string)$descriptor['node_id'])
            || !hash_equals($expectedNodeId, (string)($signature['key_id'] ?? ''))) {
            throw new FederationException('Identidad criptográfica del nodo remoto inconsistente.', 502);
        }
        $signed = $descriptor;
        unset($signed['signature']);
        $signatureBytes = FederationCodec::base64UrlDecode((string)$signature['value']);
        if (!NodeIdentityService::verify(FederationCodec::canonicalJson($signed), $signatureBytes, $publicKey)) {
            throw new FederationException('Firma del descriptor remoto inválida.', 502);
        }
        return $descriptor;
    }

    private function resolveLocal(array $document, int $viewerUserId): array
    {
        $payload = $this->links->decryptLocalPayload($document);
        $ownerId = (int)$payload['user_id'];
        $file = $this->findPayloadFile($payload);
        if ($file === null) {
            return $this->baseResult($document, 'not_available', false, true);
        }
        if ((string)$document['visibility'] === 'PRIVATE' && $viewerUserId !== $ownerId) {
            return $this->baseResult($document, 'private_auth_required', false, false);
        }
        if ((string)($file['AccessType'] ?? 'normal') === 'secure') {
            return $this->baseResult($document, 'secure_resource_requires_drive', false, false);
        }
        $signedContentId = $document['content_id'] ?? null;
        $currentContentId = $this->resources->contentId($file);
        if (is_string($signedContentId) && is_string($currentContentId) && !hash_equals($signedContentId, $currentContentId)) {
            return $this->baseResult($document, 'content_changed', false, true);
        }
        $result = $this->baseResult($document, 'available', true, false);
        $result['local'] = true;
        $result['content_verified'] = is_string($signedContentId) && is_string($currentContentId)
            ? hash_equals($signedContentId, $currentContentId)
            : null;
        $result['recovered_by_storage_ref'] = (int)($payload['version'] ?? 1) >= 2
            && (int)($file['id_'] ?? 0) !== (int)($payload['file_id'] ?? 0);
        return $result;
    }

    private function findPayloadFile(array $payload): ?array
    {
        $ownerId = (int)($payload['user_id'] ?? 0);
        $fileId = (int)($payload['file_id'] ?? 0);
        $file = $this->resources->findOwnedFile($ownerId, $fileId);

        if ((int)($payload['version'] ?? 1) < 2) {
            return $file;
        }

        $storageRef = trim((string)($payload['storage_ref'] ?? ''));
        if ($storageRef === '') {
            return null;
        }
        if ($file !== null && hash_equals($storageRef, (string)($file['Encriptado'] ?? ''))) {
            return $file;
        }

        return $this->resources->findOwnedFileByStorageRef($ownerId, $storageRef);
    }

    private function resolveRemote(array $document): array
    {
        try {
            $descriptor = $this->publicNodeDescriptor(
                $this->http->getJson((string)$document['federation_url'], 'node.php')
            );
            $linkKey = (string)($document['signature']['public_key'] ?? '');
            if (!hash_equals((string)$document['origin_node_id'], (string)$descriptor['node_id'])
                || !hash_equals($linkKey, (string)$descriptor['public_key'])) {
                return $this->baseResult($document, 'origin_identity_mismatch', false, false);
            }
            $remote = $this->http->postJson((string)$document['federation_url'], 'resolve.php', [
                'arcadelink' => $document,
            ]);
            if (($remote['ok'] ?? false) !== true || !is_array($remote['resource'] ?? null)) {
                return $this->baseResult($document, 'origin_unavailable', false, true);
            }
            $status = (string)($remote['resource']['status'] ?? 'origin_unavailable');
            $allowed = ['available', 'not_available', 'private_auth_required', 'secure_resource_requires_drive', 'content_changed'];
            if (!in_array($status, $allowed, true)) $status = 'origin_unavailable';
            $result = $this->baseResult($document, $status, false, in_array($status, ['not_available', 'content_changed'], true));
            $result['origin_reachable'] = true;
            $result['origin_identity_verified'] = true;
            $result['local'] = false;
            return $result;
        } catch (FederationException $e) {
            $result = $this->baseResult($document, 'origin_unreachable', false, true);
            $result['origin_reachable'] = false;
            $result['origin_error'] = $e->getMessage();
            $result['local'] = false;
            return $result;
        }
    }

    private function baseResult(array $document, string $status, bool $canOpen, bool $mirrorReady): array
    {
        $visibility = (string)$document['visibility'];
        return [
            'status' => $status,
            'resource_id' => (string)$document['resource_id'],
            'title' => (string)$document['title'],
            'resource_type' => (string)$document['resource_type'],
            'size_bytes' => (int)$document['size_bytes'],
            'media_type' => (string)$document['media_type'],
            'origin_node_id' => (string)$document['origin_node_id'],
            'origin' => (string)$document['origin'],
            'federation_url' => (string)$document['federation_url'],
            'visibility' => $visibility,
            'rights' => (string)$document['rights'],
            'content_id' => $visibility === 'PRIVATE' ? null : ($document['content_id'] ?? null),
            'issued_at' => (string)$document['issued_at'],
            'signature_valid' => true,
            'can_open' => $canOpen,
            'can_download' => $canOpen && in_array((string)$document['rights'], ['copy_allowed', 'user_owned_authorized'], true),
            'can_save' => false,
            'mirror_lookup_ready' => $mirrorReady && $visibility !== 'PRIVATE' && is_string($document['content_id'] ?? null),
        ];
    }
}
