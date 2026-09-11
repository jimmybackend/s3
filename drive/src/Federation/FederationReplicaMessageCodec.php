<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationReplicaMessageCodec
{
    public const FORMAT = 'arcadecloud-replica-offer';
    public const VERSION = 1;

    public function createOffer(
        NodeIdentityService $identity,
        string $targetNodeId,
        string $role,
        string $resourceId,
        string $sourceUrl,
        string $contentId,
        int $sizeBytes,
        string $title,
        string $mediaType,
        array $providerGrant,
        ?string $offerId = null,
        ?string $issuedAt = null,
        ?string $expiresAt = null
    ): array {
        $role = FederationProviderGrant::normalizeRole($role);
        $offerId = $offerId ?: 'fro_' . FederationCodec::base64UrlEncode(random_bytes(18));
        $issuedAt = $issuedAt ?: gmdate(DATE_ATOM);
        $expiresAt = $expiresAt ?: gmdate(DATE_ATOM, time() + 10 * 60);
        $this->validateIds($offerId, $resourceId, $identity->nodeId(), $targetNodeId);
        $this->validateTimes($issuedAt, $expiresAt);
        $sourceUrl = $this->httpsUrl($sourceUrl);
        $contentId = strtolower(trim($contentId));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) {
            throw new FederationException('La réplica requiere Content ID SHA-256.', 409);
        }
        if ($sizeBytes < 0) throw new FederationException('Tamaño de réplica inválido.', 400);
        if (!FederationProviderGrant::verify($providerGrant, $identity->publicKeyEncoded())) {
            throw new FederationException('Grant de proveedor inválido para oferta de réplica.', 409);
        }

        $document = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'offer_id' => $offerId,
            'resource_id' => $resourceId,
            'origin_node_id' => $identity->nodeId(),
            'target_node_id' => $targetNodeId,
            'role' => $role,
            'source_url' => $sourceUrl,
            'content_id' => $contentId,
            'size_bytes' => $sizeBytes,
            'title' => $this->safeText($title, 255, 'Recurso FederationCloud'),
            'media_type' => $this->safeText($mediaType, 128, 'application/octet-stream'),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'provider_grant' => $providerGrant,
            'public_key' => $identity->publicKeyEncoded(),
        ];
        $document['signature'] = FederationCodec::base64UrlEncode(
            $identity->sign(FederationCodec::canonicalJson($document))
        );
        return $document;
    }

    public function verifyOffer(array $document): array
    {
        if (($document['format'] ?? null) !== self::FORMAT || (int)($document['version'] ?? 0) !== self::VERSION) {
            throw new FederationException('Formato de oferta de réplica no soportado.', 400);
        }
        $offerId = (string)($document['offer_id'] ?? '');
        $resourceId = (string)($document['resource_id'] ?? '');
        $originNodeId = (string)($document['origin_node_id'] ?? '');
        $targetNodeId = (string)($document['target_node_id'] ?? '');
        $role = FederationProviderGrant::normalizeRole((string)($document['role'] ?? ''));
        $this->validateIds($offerId, $resourceId, $originNodeId, $targetNodeId);
        $this->validateTimes((string)($document['issued_at'] ?? ''), (string)($document['expires_at'] ?? ''));
        if ((strtotime((string)$document['expires_at']) ?: 0) < time() - 60) {
            throw new FederationException('La oferta de réplica expiró.', 410);
        }
        $this->httpsUrl((string)($document['source_url'] ?? ''));
        $contentId = strtolower((string)($document['content_id'] ?? ''));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId) || (int)($document['size_bytes'] ?? -1) < 0) {
            throw new FederationException('Integridad de oferta de réplica inválida.', 400);
        }

        $publicKeyEncoded = (string)($document['public_key'] ?? '');
        $publicKey = FederationCodec::base64UrlDecode($publicKeyEncoded);
        $signature = FederationCodec::base64UrlDecode((string)($document['signature'] ?? ''));
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new FederationException('Firma de oferta de réplica inválida.', 400);
        }
        if (!hash_equals(NodeIdentityService::nodeIdFromPublicKey($publicKey), $originNodeId)) {
            throw new FederationException('La clave de oferta no corresponde al nodo origen.', 409);
        }
        $signed = $document;
        unset($signed['signature']);
        if (!NodeIdentityService::verify(FederationCodec::canonicalJson($signed), $signature, $publicKey)) {
            throw new FederationException('Firma Ed25519 de oferta de réplica inválida.', 409);
        }

        $grant = $document['provider_grant'] ?? null;
        if (!is_array($grant) || !FederationProviderGrant::verify($grant, $publicKeyEncoded)) {
            throw new FederationException('Grant de proveedor de la réplica inválido.', 403);
        }
        if (!hash_equals($originNodeId, (string)($grant['origin_node_id'] ?? ''))
            || !hash_equals($targetNodeId, (string)($grant['provider_node_id'] ?? ''))
            || !hash_equals($role, (string)($grant['role'] ?? ''))) {
            throw new FederationException('La oferta no coincide con el grant de proveedor.', 403);
        }
        if (!hash_equals('all_allowed_resources', (string)($grant['scope'] ?? ''))) {
            throw new FederationException('La replicación automática requiere scope all_allowed_resources.', 403);
        }
        return $document;
    }

    private function validateIds(string $offerId, string $resourceId, string $originNodeId, string $targetNodeId): void
    {
        if (!preg_match('/\Afro_[A-Za-z0-9_-]{16,80}\z/', $offerId)
            || !preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)
            || !preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', $originNodeId)
            || !preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', $targetNodeId)) {
            throw new FederationException('Identidad de oferta de réplica inválida.', 400);
        }
    }

    private function validateTimes(string $issuedAt, string $expiresAt): void
    {
        $issued = strtotime($issuedAt);
        $expires = strtotime($expiresAt);
        if ($issued === false || $expires === false || $issued > time() + 300 || $expires <= $issued || $expires > $issued + 20 * 60) {
            throw new FederationException('Ventana temporal de réplica inválida.', 400);
        }
    }

    private function httpsUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || strlen($url) > 8192) {
            throw new FederationException('URL de origen de réplica inválida.', 400);
        }
        return $url;
    }

    private function safeText(string $value, int $max, string $fallback): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value);
        if ($value === '') $value = $fallback;
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
