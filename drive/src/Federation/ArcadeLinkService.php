<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use JsonException;

final class ArcadeLinkService
{
    public const MAX_BYTES = 65536;
    private const VISIBILITIES = ['PUBLIC', 'UNLISTED', 'PRIVATE'];
    private const RIGHTS = ['copy_allowed', 'link_only', 'unknown_rights', 'user_owned_authorized'];

    public function __construct(
        private FederationConfig $config,
        private NodeIdentityService $identity
    ) {
    }

    public function create(array $file, int $userId, ?string $contentId, string $mediaType, string $visibility, string $rights): array
    {
        $visibility = strtoupper(trim($visibility));
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new FederationException('Visibilidad ArcadeLink inválida.');
        }
        $rights = strtolower(trim($rights));
        if (!in_array($rights, self::RIGHTS, true)) {
            throw new FederationException('Política de derechos ArcadeLink inválida.');
        }
        if ((string)($file['AccessType'] ?? 'normal') === 'secure' && $visibility !== 'PRIVATE') {
            throw new FederationException('Un archivo protegido sólo puede emitirse como PRIVATE.', 409);
        }

        $fileId = (int)($file['id_'] ?? 0);
        if ($userId <= 0 || $fileId <= 0) {
            throw new FederationException('Referencia local de recurso inválida.', 500);
        }
        $resourceId = $this->resourceId($userId, $fileId);
        if ($visibility === 'PRIVATE') {
            $contentId = null;
        } elseif ($contentId !== null && !preg_match('/\Asha256:[a-f0-9]{64}\z/', strtolower($contentId))) {
            throw new FederationException('Content ID SHA-256 inválido.', 500);
        }

        $document = [
            'format' => 'arcadelink',
            'version' => 1,
            'resource_id' => $resourceId,
            'origin_node_id' => $this->identity->nodeId(),
            'origin' => $this->config->publicUrl(),
            'federation_url' => $this->config->federationUrl(),
            'resource_type' => 'file',
            'title' => $this->safeText((string)($file['Nombre'] ?? 'Recurso'), 255),
            'size_bytes' => max(0, (int)($file['Tamano'] ?? 0)),
            'media_type' => $this->safeText($mediaType, 128),
            'visibility' => $visibility,
            'rights' => $rights,
            'content_id' => $contentId !== null ? strtolower($contentId) : null,
            'issued_at' => gmdate(DATE_ATOM),
        ];

        $privatePayload = [
            'version' => 1,
            'user_id' => $userId,
            'file_id' => $fileId,
            'resource_id' => $resourceId,
        ];
        $aad = FederationCodec::canonicalJson($document);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plaintext = FederationCodec::canonicalJson($privatePayload);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            $this->identity->payloadKey()
        );
        $document['payload'] = [
            'alg' => 'XChaCha20-Poly1305',
            'nonce' => FederationCodec::base64UrlEncode($nonce),
            'ciphertext' => FederationCodec::base64UrlEncode($ciphertext),
        ];

        $signedBytes = FederationCodec::canonicalJson($document);
        $document['signature'] = [
            'alg' => 'Ed25519',
            'key_id' => $this->identity->nodeId(),
            'public_key' => $this->identity->publicKeyEncoded(),
            'value' => FederationCodec::base64UrlEncode($this->identity->sign($signedBytes)),
        ];
        return $document;
    }

    public function encode(array $document, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if ($pretty) $flags |= JSON_PRETTY_PRINT;
        return json_encode($document, $flags) . "\n";
    }

    public function parse(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw new FederationException('El archivo .arcadelink está vacío o excede 64 KiB.');
        }
        try {
            $document = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('El archivo .arcadelink no contiene JSON válido.');
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new FederationException('El documento ArcadeLink debe ser un objeto JSON.');
        }
        $this->validateDocument($document);
        $signature = $document['signature'];
        $publicKey = FederationCodec::base64UrlDecode((string)$signature['public_key']);
        $signatureBytes = FederationCodec::base64UrlDecode((string)$signature['value']);
        $expectedNodeId = NodeIdentityService::nodeIdFromPublicKey($publicKey);
        if (!hash_equals($expectedNodeId, (string)$document['origin_node_id'])
            || !hash_equals($expectedNodeId, (string)$signature['key_id'])) {
            throw new FederationException('La clave pública no corresponde al Node ID del ArcadeLink.');
        }
        $signed = $document;
        unset($signed['signature']);
        if (!NodeIdentityService::verify(FederationCodec::canonicalJson($signed), $signatureBytes, $publicKey)) {
            throw new FederationException('La firma Ed25519 del ArcadeLink no es válida.');
        }
        return $document;
    }

    public function decryptLocalPayload(array $document): array
    {
        if (!hash_equals($this->identity->nodeId(), (string)($document['origin_node_id'] ?? ''))) {
            throw new FederationException('Este ArcadeLink no pertenece al nodo local.', 409);
        }
        $payload = $document['payload'] ?? null;
        if (!is_array($payload)) {
            throw new FederationException('Payload ArcadeLink ausente.');
        }
        $nonce = FederationCodec::base64UrlDecode((string)($payload['nonce'] ?? ''));
        $ciphertext = FederationCodec::base64UrlDecode((string)($payload['ciphertext'] ?? ''));
        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new FederationException('Nonce ArcadeLink inválido.');
        }
        $public = $document;
        unset($public['payload'], $public['signature']);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            FederationCodec::canonicalJson($public),
            $nonce,
            $this->identity->payloadKey()
        );
        if (!is_string($plaintext)) {
            throw new FederationException('No se pudo descifrar el payload del ArcadeLink.');
        }
        try {
            $decoded = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('Payload ArcadeLink descifrado inválido.');
        }
        if (!is_array($decoded)
            || (int)($decoded['version'] ?? 0) !== 1
            || (int)($decoded['user_id'] ?? 0) <= 0
            || (int)($decoded['file_id'] ?? 0) <= 0
            || !hash_equals((string)($document['resource_id'] ?? ''), (string)($decoded['resource_id'] ?? ''))) {
            throw new FederationException('Payload ArcadeLink inconsistente.');
        }
        $expected = $this->resourceId((int)$decoded['user_id'], (int)$decoded['file_id']);
        if (!hash_equals($expected, (string)$document['resource_id'])) {
            throw new FederationException('Resource ID ArcadeLink inconsistente.');
        }
        return $decoded;
    }

    public function resourceId(int $userId, int $fileId): string
    {
        $message = 'arcadelink:v1:resource:' . $userId . ':' . $fileId;
        $digest = hash_hmac('sha256', $message, $this->identity->payloadKey(), true);
        return 'arl_' . FederationCodec::base64UrlEncode(substr($digest, 0, 18));
    }

    public function suggestedFilename(array $document): string
    {
        $base = trim((string)($document['title'] ?? 'recurso'));
        $base = preg_replace('/[^\pL\pN._ -]+/u', '-', $base) ?? 'recurso';
        $base = trim($base, " .-_\t\n\r\0\x0B");
        if ($base === '') $base = 'recurso';
        if (function_exists('mb_substr')) $base = mb_substr($base, 0, 120);
        else $base = substr($base, 0, 120);
        return $base . '.arcadelink';
    }

    private function validateDocument(array $document): void
    {
        $requiredStrings = [
            'format' => 32, 'resource_id' => 96, 'origin_node_id' => 96,
            'origin' => 2048, 'federation_url' => 2048, 'resource_type' => 32,
            'title' => 255, 'media_type' => 128, 'visibility' => 16,
            'rights' => 64, 'issued_at' => 64,
        ];
        foreach ($requiredStrings as $field => $max) {
            if (!is_string($document[$field] ?? null) || $document[$field] === '' || strlen($document[$field]) > $max) {
                throw new FederationException('Campo ArcadeLink inválido: ' . $field . '.');
            }
        }
        if ($document['format'] !== 'arcadelink' || (int)($document['version'] ?? 0) !== 1) {
            throw new FederationException('Formato o versión ArcadeLink no soportados.');
        }
        if (!preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $document['resource_id'])
            || !preg_match('/\Aacn_[A-Za-z0-9_-]{16,80}\z/', $document['origin_node_id'])) {
            throw new FederationException('Identidad ArcadeLink inválida.');
        }
        if ($document['resource_type'] !== 'file') {
            throw new FederationException('Tipo de recurso ArcadeLink no soportado en v1.');
        }
        if (!in_array($document['visibility'], self::VISIBILITIES, true)
            || !in_array($document['rights'], self::RIGHTS, true)) {
            throw new FederationException('Visibilidad o derechos ArcadeLink inválidos.');
        }
        if (!is_int($document['size_bytes'] ?? null) || $document['size_bytes'] < 0) {
            throw new FederationException('Tamaño ArcadeLink inválido.');
        }
        $contentId = $document['content_id'] ?? null;
        if ($document['visibility'] === 'PRIVATE' && $contentId !== null) {
            throw new FederationException('Un ArcadeLink PRIVATE no puede publicar Content ID.');
        }
        if ($contentId !== null && (!is_string($contentId) || !preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId))) {
            throw new FederationException('Content ID ArcadeLink inválido.');
        }
        foreach (['origin', 'federation_url'] as $field) {
            $parts = parse_url($document[$field]);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
                || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])) {
                throw new FederationException('URL ArcadeLink inválida: ' . $field . '.');
            }
        }
        if (!is_array($document['payload'] ?? null)
            || ($document['payload']['alg'] ?? null) !== 'XChaCha20-Poly1305'
            || !is_string($document['payload']['nonce'] ?? null)
            || !is_string($document['payload']['ciphertext'] ?? null)) {
            throw new FederationException('Payload ArcadeLink inválido.');
        }
        $signature = $document['signature'] ?? null;
        if (!is_array($signature)
            || ($signature['alg'] ?? null) !== 'Ed25519'
            || !is_string($signature['key_id'] ?? null)
            || !is_string($signature['public_key'] ?? null)
            || !is_string($signature['value'] ?? null)) {
            throw new FederationException('Firma ArcadeLink inválida.');
        }
    }

    private function safeText(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value);
        if ($value === '') $value = 'application/octet-stream';
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
