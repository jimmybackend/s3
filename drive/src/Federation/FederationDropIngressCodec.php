<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationDropIngressCodec
{
    public const PROTOCOL = 'arcadecloud-federation-drop-ingress';
    public const VERSION = 1;

    public function createGrant(
        NodeIdentityService $commerceIdentity,
        string $commerceFederationUrl,
        string $targetNodeId,
        string $dropId,
        string $ingressId,
        string $filename,
        string $mimeType,
        int $expectedSizeBytes,
        int $ttlSeconds = 172800
    ): array {
        $ttlSeconds = max(1800, min(259200, $ttlSeconds));
        $issued = time();
        $grant = [
            'protocol' => self::PROTOCOL,
            'version' => self::VERSION,
            'grant_type' => 'temporary_ingress',
            'ingress_id' => $ingressId,
            'drop_id' => $dropId,
            'commerce_node_id' => $commerceIdentity->nodeId(),
            'commerce_federation_url' => rtrim($commerceFederationUrl, '/') . '/',
            'target_node_id' => $targetNodeId,
            'filename' => $this->safeText($filename, 255),
            'mime_type' => $this->safeText($mimeType, 128),
            'expected_size_bytes' => $expectedSizeBytes,
            'issued_at' => gmdate(DATE_ATOM, $issued),
            'expires_at' => gmdate(DATE_ATOM, $issued + $ttlSeconds),
            'nonce' => FederationCodec::base64UrlEncode(random_bytes(18)),
        ];
        $grant['signature'] = [
            'alg' => 'Ed25519',
            'key_id' => $commerceIdentity->nodeId(),
            'value' => FederationCodec::base64UrlEncode(
                $commerceIdentity->sign(FederationCodec::canonicalJson($grant))
            ),
        ];
        return $grant;
    }

    public function verifyGrant(array $grant, array $commerceDescriptor): array
    {
        $signature = $grant['signature'] ?? null;
        if (!is_array($signature)
            || ($grant['protocol'] ?? null) !== self::PROTOCOL
            || (int)($grant['version'] ?? 0) !== self::VERSION
            || ($grant['grant_type'] ?? null) !== 'temporary_ingress'
            || ($signature['alg'] ?? null) !== 'Ed25519') {
            throw new FederationException('Grant FederationDrop ingress inválido.', 400);
        }

        foreach (['ingress_id','drop_id','commerce_node_id','commerce_federation_url','target_node_id','filename','mime_type','issued_at','expires_at','nonce'] as $field) {
            if (!is_string($grant[$field] ?? null) || trim((string)$grant[$field]) === '') {
                throw new FederationException('Grant FederationDrop ingress incompleto.', 400);
            }
        }
        if (!preg_match('/\Afdi_[A-Za-z0-9_-]{16,80}\z/', (string)$grant['ingress_id'])
            || !preg_match('/\Afdp_[A-Za-z0-9_-]{16,80}\z/', (string)$grant['drop_id'])
            || !preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', (string)$grant['commerce_node_id'])
            || !preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', (string)$grant['target_node_id'])) {
            throw new FederationException('Identificadores FederationDrop ingress inválidos.', 400);
        }

        $size = (int)($grant['expected_size_bytes'] ?? 0);
        if ($size <= 0 || $size > FederationReplicaDownloader::MAX_BYTES) {
            throw new FederationException('Tamaño FederationDrop ingress inválido.', 413);
        }

        $issued = strtotime((string)$grant['issued_at']);
        $expires = strtotime((string)$grant['expires_at']);
        $now = time();
        if ($issued === false || $expires === false || $issued > $now + 300 || $expires <= $now || $expires - $issued > 259200) {
            throw new FederationException('Grant FederationDrop ingress vencido o temporalmente inválido.', 403);
        }

        $descriptorNode = (string)($commerceDescriptor['node_id'] ?? '');
        $descriptorKey = (string)($commerceDescriptor['public_key'] ?? '');
        if ($descriptorNode === ''
            || !hash_equals($descriptorNode, (string)$grant['commerce_node_id'])
            || !hash_equals($descriptorNode, (string)($signature['key_id'] ?? ''))) {
            throw new FederationException('Grant ingress no pertenece al nodo comercial verificado.', 403);
        }

        $publicKey = FederationCodec::base64UrlDecode($descriptorKey);
        $signatureBytes = FederationCodec::base64UrlDecode((string)($signature['value'] ?? ''));
        $signed = $grant;
        unset($signed['signature']);
        if (!NodeIdentityService::verify(FederationCodec::canonicalJson($signed), $signatureBytes, $publicKey)) {
            throw new FederationException('Firma FederationDrop ingress inválida.', 403);
        }

        return $grant;
    }

    private function safeText(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value);
        if ($value === '') $value = 'application/octet-stream';
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
