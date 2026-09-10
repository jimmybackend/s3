<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationProviderGrant
{
    public const ROLES = ['provider', 'mirror'];
    public const SCOPES = ['all_allowed_resources', 'selected_resources'];

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if (!in_array($role, self::ROLES, true)) {
            throw new FederationException('Rol de proveedor FederationCloud inválido.', 400);
        }
        return $role;
    }

    public static function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, self::SCOPES, true)) {
            throw new FederationException('Alcance de proveedor FederationCloud inválido.', 400);
        }
        return $scope;
    }

    public static function payload(string $originNodeId, string $providerNodeId, string $role, string $scope): array
    {
        if (!preg_match('/\Aacn_[A-Za-z0-9_-]{20,64}\z/', $originNodeId)
            || !preg_match('/\Aacn_[A-Za-z0-9_-]{20,64}\z/', $providerNodeId)) {
            throw new FederationException('Node ID de autorización FederationCloud inválido.', 400);
        }

        return [
            'protocol' => 'arcadecloud-provider-authorization',
            'version' => 1,
            'origin_node_id' => $originNodeId,
            'provider_node_id' => $providerNodeId,
            'role' => self::normalizeRole($role),
            'scope' => self::normalizeScope($scope),
        ];
    }

    public static function sign(
        NodeIdentityService $identity,
        string $providerNodeId,
        string $role,
        string $scope
    ): array {
        $payload = self::payload($identity->nodeId(), $providerNodeId, $role, $scope);
        $payload['signature'] = [
            'alg' => 'Ed25519',
            'key_id' => $identity->nodeId(),
            'value' => FederationCodec::base64UrlEncode(
                $identity->sign(FederationCodec::canonicalJson($payload))
            ),
        ];
        return $payload;
    }

    public static function verify(array $grant, string $originPublicKeyEncoded): bool
    {
        $signature = $grant['signature'] ?? null;
        if (!is_array($signature)
            || ($signature['alg'] ?? null) !== 'Ed25519'
            || !is_string($signature['key_id'] ?? null)
            || !is_string($signature['value'] ?? null)) {
            return false;
        }

        try {
            $unsigned = self::payload(
                (string)($grant['origin_node_id'] ?? ''),
                (string)($grant['provider_node_id'] ?? ''),
                (string)($grant['role'] ?? ''),
                (string)($grant['scope'] ?? '')
            );
            if (!hash_equals((string)$unsigned['origin_node_id'], (string)$signature['key_id'])) {
                return false;
            }
            $publicKey = FederationCodec::base64UrlDecode($originPublicKeyEncoded);
            if (!hash_equals(
                (string)$unsigned['origin_node_id'],
                NodeIdentityService::nodeIdFromPublicKey($publicKey)
            )) {
                return false;
            }
            $signatureBytes = FederationCodec::base64UrlDecode((string)$signature['value']);
            return NodeIdentityService::verify(
                FederationCodec::canonicalJson($unsigned),
                $signatureBytes,
                $publicKey
            );
        } catch (FederationException) {
            return false;
        }
    }
}
