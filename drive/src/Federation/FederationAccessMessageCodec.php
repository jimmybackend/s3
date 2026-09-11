<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationAccessMessageCodec
{
    public const REQUEST_FORMAT = 'arcadecloud-access-request';
    public const DECISION_FORMAT = 'arcadecloud-access-decision';
    public const VERSION = 1;

    public function createRequest(
        NodeIdentityService $identity,
        string $resourceId,
        string $requesterFederationUrl,
        ?string $requestId = null,
        ?string $requestedAt = null,
        ?string $expiresAt = null
    ): array {
        if (!preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            throw new FederationException('Resource ID inválido para solicitud de acceso.', 400);
        }
        $requestId = $requestId ?: 'far_' . FederationCodec::base64UrlEncode(random_bytes(18));
        if (!preg_match('/\Afar_[A-Za-z0-9_-]{16,80}\z/', $requestId)) {
            throw new FederationException('Request ID federado inválido.', 400);
        }
        $requestedAt = $requestedAt ?: gmdate(DATE_ATOM);
        $expiresAt = $expiresAt ?: gmdate(DATE_ATOM, time() + 7 * 86400);
        $this->validateTimes($requestedAt, $expiresAt);
        $requesterFederationUrl = $this->httpsUrl($requesterFederationUrl);

        $document = [
            'format' => self::REQUEST_FORMAT,
            'version' => self::VERSION,
            'request_id' => $requestId,
            'resource_id' => $resourceId,
            'requester_node_id' => $identity->nodeId(),
            'requester_federation_url' => $requesterFederationUrl,
            'requested_at' => $requestedAt,
            'expires_at' => $expiresAt,
            'public_key' => $identity->publicKeyEncoded(),
        ];
        $document['signature'] = FederationCodec::base64UrlEncode(
            $identity->sign(FederationCodec::canonicalJson($document))
        );
        return $document;
    }

    public function verifyRequest(array $document): array
    {
        if (($document['format'] ?? null) !== self::REQUEST_FORMAT || (int)($document['version'] ?? 0) !== self::VERSION) {
            throw new FederationException('Formato de solicitud FederationCloud no soportado.', 400);
        }
        $requestId = (string)($document['request_id'] ?? '');
        $resourceId = (string)($document['resource_id'] ?? '');
        $nodeId = (string)($document['requester_node_id'] ?? '');
        $url = (string)($document['requester_federation_url'] ?? '');
        $requestedAt = (string)($document['requested_at'] ?? '');
        $expiresAt = (string)($document['expires_at'] ?? '');
        $publicKeyEncoded = (string)($document['public_key'] ?? '');
        $signatureEncoded = (string)($document['signature'] ?? '');
        if (!preg_match('/\Afar_[A-Za-z0-9_-]{16,80}\z/', $requestId)
            || !preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)
            || !preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', $nodeId)) {
            throw new FederationException('Identidad de solicitud FederationCloud inválida.', 400);
        }
        $this->httpsUrl($url);
        $this->validateTimes($requestedAt, $expiresAt);
        if ((strtotime($expiresAt) ?: 0) < time() - 300) throw new FederationException('La solicitud de acceso ya expiró.', 410);

        $publicKey = FederationCodec::base64UrlDecode($publicKeyEncoded);
        $signature = FederationCodec::base64UrlDecode($signatureEncoded);
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new FederationException('Firma de solicitud FederationCloud inválida.', 400);
        }
        if (!hash_equals(NodeIdentityService::nodeIdFromPublicKey($publicKey), $nodeId)) {
            throw new FederationException('La clave pública no corresponde al nodo solicitante.', 409);
        }
        $signed = $document;
        unset($signed['signature']);
        if (!NodeIdentityService::verify(FederationCodec::canonicalJson($signed), $signature, $publicKey)) {
            throw new FederationException('Firma Ed25519 de solicitud inválida.', 409);
        }
        return $document;
    }

    public function createDecision(
        NodeIdentityService $identity,
        array $request,
        string $decision,
        ?string $accessUrl = null,
        ?string $expiresAt = null,
        ?string $decidedAt = null
    ): array {
        $request = $this->verifyRequest($request);
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new FederationException('Decisión FederationCloud inválida.', 400);
        }
        if ($decision === 'approved') {
            if ($accessUrl === null || trim($accessUrl) === '') throw new FederationException('Una aprobación requiere URL temporal.', 500);
            $accessUrl = $this->httpsUrl($accessUrl);
            if ($expiresAt === null || strtotime($expiresAt) === false) throw new FederationException('Una aprobación requiere expiración válida.', 500);
        } else {
            $accessUrl = null;
            $expiresAt = null;
        }
        $decidedAt = $decidedAt ?: gmdate(DATE_ATOM);
        if (strtotime($decidedAt) === false) throw new FederationException('Fecha de decisión inválida.', 500);

        $document = [
            'format' => self::DECISION_FORMAT,
            'version' => self::VERSION,
            'request_id' => (string)$request['request_id'],
            'resource_id' => (string)$request['resource_id'],
            'requester_node_id' => (string)$request['requester_node_id'],
            'origin_node_id' => $identity->nodeId(),
            'decision' => $decision,
            'decided_at' => $decidedAt,
            'expires_at' => $expiresAt,
            'access_url' => $accessUrl,
            'public_key' => $identity->publicKeyEncoded(),
        ];
        $document['signature'] = FederationCodec::base64UrlEncode(
            $identity->sign(FederationCodec::canonicalJson($document))
        );
        return $document;
    }

    public function verifyDecision(array $document, array $request): array
    {
        $request = $this->verifyRequest($request);
        if (($document['format'] ?? null) !== self::DECISION_FORMAT || (int)($document['version'] ?? 0) !== self::VERSION) {
            throw new FederationException('Formato de decisión FederationCloud no soportado.', 400);
        }
        foreach (['request_id', 'resource_id', 'requester_node_id'] as $field) {
            if (!hash_equals((string)$request[$field], (string)($document[$field] ?? ''))) {
                throw new FederationException('La decisión no corresponde a la solicitud original.', 409);
            }
        }
        $originNodeId = (string)($document['origin_node_id'] ?? '');
        $decision = (string)($document['decision'] ?? '');
        $publicKeyEncoded = (string)($document['public_key'] ?? '');
        $signatureEncoded = (string)($document['signature'] ?? '');
        if (!preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', $originNodeId)
            || !in_array($decision, ['approved','rejected'], true)) {
            throw new FederationException('Decisión FederationCloud inválida.', 400);
        }
        if ($decision === 'approved') {
            $this->httpsUrl((string)($document['access_url'] ?? ''));
            $expiresAt = (string)($document['expires_at'] ?? '');
            if (strtotime($expiresAt) === false) throw new FederationException('Expiración de grant inválida.', 400);
        } elseif (($document['access_url'] ?? null) !== null) {
            throw new FederationException('Una decisión rechazada no puede incluir URL de acceso.', 400);
        }

        $publicKey = FederationCodec::base64UrlDecode($publicKeyEncoded);
        $signature = FederationCodec::base64UrlDecode($signatureEncoded);
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new FederationException('Firma de decisión FederationCloud inválida.', 400);
        }
        if (!hash_equals(NodeIdentityService::nodeIdFromPublicKey($publicKey), $originNodeId)) {
            throw new FederationException('La decisión no corresponde a la clave del nodo origen.', 409);
        }
        $signed = $document;
        unset($signed['signature']);
        if (!NodeIdentityService::verify(FederationCodec::canonicalJson($signed), $signature, $publicKey)) {
            throw new FederationException('Firma Ed25519 de decisión inválida.', 409);
        }
        return $document;
    }

    private function validateTimes(string $requestedAt, string $expiresAt): void
    {
        $requested = strtotime($requestedAt);
        $expires = strtotime($expiresAt);
        if ($requested === false || $expires === false || $requested > time() + 300 || $expires <= $requested || $expires > $requested + 30 * 86400) {
            throw new FederationException('Ventana temporal de solicitud FederationCloud inválida.', 400);
        }
    }

    private function httpsUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || strlen($url) > 2048) {
            throw new FederationException('URL FederationCloud inválida; se requiere HTTPS.', 400);
        }
        return $url;
    }
}
