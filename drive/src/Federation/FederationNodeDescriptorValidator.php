<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationNodeDescriptorValidator
{
    public function validate(array $descriptor): array
    {
        if (($descriptor['protocol'] ?? null) !== 'arcadecloud-federation' || (int)($descriptor['version'] ?? 0) !== 1) {
            throw new FederationException('Descriptor FederationCloud no soportado.', 400);
        }

        foreach (['node_id', 'public_url', 'federation_url', 'public_key'] as $field) {
            if (!is_string($descriptor[$field] ?? null) || trim((string)$descriptor[$field]) === '') {
                throw new FederationException('Descriptor FederationCloud incompleto.', 400);
            }
        }

        $nodeId = (string)$descriptor['node_id'];
        if (!preg_match('/\Aacn_[A-Za-z0-9_-]{20,64}\z/', $nodeId)) {
            throw new FederationException('Node ID FederationCloud inválido.', 400);
        }

        $publicKey = FederationCodec::base64UrlDecode((string)$descriptor['public_key']);
        if (!hash_equals(NodeIdentityService::nodeIdFromPublicKey($publicKey), $nodeId)) {
            throw new FederationException('El Node ID no corresponde a la clave pública.', 400);
        }

        $publicUrl = $this->validateUrl((string)$descriptor['public_url'], false);
        $federationUrl = $this->validateUrl((string)$descriptor['federation_url'], true);

        $algorithms = $descriptor['algorithms'] ?? null;
        if (!is_array($algorithms)
            || ($algorithms['signature'] ?? null) !== 'Ed25519'
            || ($algorithms['payload'] ?? null) !== 'XChaCha20-Poly1305'
            || ($algorithms['content_id'] ?? null) !== 'SHA-256') {
            throw new FederationException('Algoritmos FederationCloud no soportados.', 400);
        }

        $signature = $descriptor['signature'] ?? null;
        if (!is_array($signature)
            || ($signature['alg'] ?? null) !== 'Ed25519'
            || ($signature['key_id'] ?? null) !== $nodeId
            || !is_string($signature['value'] ?? null)
            || trim((string)$signature['value']) === '') {
            throw new FederationException('Firma de descriptor FederationCloud inválida.', 400);
        }

        $unsigned = $descriptor;
        unset($unsigned['signature']);
        $signatureBytes = FederationCodec::base64UrlDecode((string)$signature['value']);
        if (!NodeIdentityService::verify(FederationCodec::canonicalJson($unsigned), $signatureBytes, $publicKey)) {
            throw new FederationException('La firma del nodo FederationCloud no es válida.', 400);
        }

        return [
            'protocol' => 'arcadecloud-federation',
            'version' => 1,
            'node_id' => $nodeId,
            'public_url' => $publicUrl,
            'federation_url' => $federationUrl,
            'public_key' => (string)$descriptor['public_key'],
            'algorithms' => $algorithms,
            'signature' => $signature,
        ];
    }

    private function validateUrl(string $url, bool $trailingSlash): string
    {
        $url = trim($url);
        if (strlen($url) > 512) {
            throw new FederationException('URL de nodo FederationCloud demasiado larga.', 400);
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new FederationException('Los nodos FederationCloud deben publicar URLs HTTPS válidas.', 400);
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            throw new FederationException('Los nodos FederationCloud sólo pueden usar HTTPS puerto 443.', 400);
        }
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($host === '' || strlen($host) > 253 || !preg_match('/\A[a-z0-9.-]+\z/', $host)) {
            throw new FederationException('Host FederationCloud inválido.', 400);
        }

        return $trailingSlash ? rtrim($url, '/') . '/' : rtrim($url, '/');
    }
}
