<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use JsonException;

final class NodeIdentityService
{
    private ?array $identity = null;

    public function __construct(private string $path)
    {
    }

    public static function initialize(string $path, bool $overwrite = false, ?string $nodeName = null): array
    {
        if (!extension_loaded('sodium')) {
            throw new FederationException('La extensión sodium de PHP es obligatoria para FederationCloud.', 500);
        }
        if ($path === '' || $path[0] !== '/') {
            throw new FederationException('La ruta de identidad debe ser absoluta.', 500);
        }
        if (is_file($path) && !$overwrite) {
            throw new FederationException('La identidad del nodo ya existe; no se sobrescribió.', 409);
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new FederationException('No se pudo crear el directorio de identidad.', 500);
        }

        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $secret = sodium_crypto_sign_secretkey($pair);
        $payloadKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $data = [
            'version' => 1,
            'node_id' => self::nodeIdFromPublicKey($public),
            'public_key' => FederationCodec::base64UrlEncode($public),
            'secret_key' => FederationCodec::base64UrlEncode($secret),
            'payload_key' => FederationCodec::base64UrlEncode($payloadKey),
            'created_at' => gmdate(DATE_ATOM),
        ];
        if ($nodeName !== null && trim($nodeName) !== '') {
            $data['node_name'] = self::normalizeNodeName($nodeName);
        }
        self::writeIdentity($path, $data);
        return $data;
    }

    public function nodeId(): string
    {
        return (string)$this->load()['node_id'];
    }

    public function nodeName(): ?string
    {
        $name = $this->load()['node_name'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    public function assignNodeName(string $nodeName): array
    {
        $name = self::normalizeNodeName($nodeName);
        $data = $this->load();
        $existing = is_string($data['node_name'] ?? null) ? (string)$data['node_name'] : '';
        if ($existing !== '' && !hash_equals($existing, $name)) {
            throw new FederationException('La identidad ya tiene un nombre de nodo distinto; no se cambió automáticamente.', 409);
        }
        if ($existing === $name) {
            return $data;
        }

        return $this->persistNodeName($data, $name);
    }

    /**
     * Renombra únicamente la etiqueta firmada del nodo.
     * El node_id y las claves criptográficas permanecen intactos.
     * Debe invocarse sólo desde un flujo administrativo autenticado.
     */
    public function renameNodeName(string $nodeName): array
    {
        $name = self::normalizeNodeName($nodeName);
        $data = $this->load();
        $existing = is_string($data['node_name'] ?? null) ? (string)$data['node_name'] : '';
        if ($existing === $name) {
            return $data;
        }

        return $this->persistNodeName($data, $name);
    }

    public function publicKey(): string
    {
        return FederationCodec::base64UrlDecode((string)$this->load()['public_key']);
    }

    public function publicKeyEncoded(): string
    {
        return (string)$this->load()['public_key'];
    }

    public function payloadKey(): string
    {
        return FederationCodec::base64UrlDecode((string)$this->load()['payload_key']);
    }

    public function sign(string $message): string
    {
        $secret = FederationCodec::base64UrlDecode((string)$this->load()['secret_key']);
        return sodium_crypto_sign_detached($message, $secret);
    }

    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }

    public static function nodeIdFromPublicKey(string $publicKey): string
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new FederationException('Clave pública Ed25519 inválida.');
        }
        $digest = sodium_crypto_generichash($publicKey, '', 18);
        return 'acn_' . FederationCodec::base64UrlEncode($digest);
    }

    public static function normalizeNodeName(string $nodeName): string
    {
        $name = strtolower(trim($nodeName));
        if (strlen($name) < 3 || strlen($name) > 64
            || !preg_match('/\A[a-z0-9][a-z0-9._-]*[a-z0-9]\z/', $name)) {
            throw new FederationException('El nombre del nodo debe tener 3-64 caracteres: a-z, 0-9, punto, guion o guion bajo.', 400);
        }
        return $name;
    }

    public function signedDescriptor(FederationConfig $config): array
    {
        $descriptor = [
            'protocol' => 'arcadecloud-federation',
            'version' => 1,
            'node_id' => $this->nodeId(),
        ];
        $nodeName = $this->nodeName();
        if ($nodeName !== null) {
            $descriptor['node_name'] = $nodeName;
        }
        $descriptor += [
            'public_url' => $config->publicUrl(),
            'federation_url' => $config->federationUrl(),
            'public_key' => $this->publicKeyEncoded(),
            'algorithms' => [
                'signature' => 'Ed25519',
                'payload' => 'XChaCha20-Poly1305',
                'content_id' => 'SHA-256',
            ],
        ];
        $descriptor['signature'] = [
            'alg' => 'Ed25519',
            'key_id' => $this->nodeId(),
            'value' => FederationCodec::base64UrlEncode($this->sign(FederationCodec::canonicalJson($descriptor))),
        ];
        return $descriptor;
    }

    private function persistNodeName(array $data, string $name): array
    {
        $data['node_name'] = $name;
        self::writeIdentity($this->path, $data);
        $this->identity = $data;
        return $data;
    }

    private function load(): array
    {
        if ($this->identity !== null) return $this->identity;
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new FederationException('La identidad criptográfica de FederationCloud no existe o no es legible.', 503);
        }
        try {
            $decoded = json_decode((string)file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('La identidad criptográfica del nodo no es JSON válido.', 500);
        }
        if (!is_array($decoded) || (int)($decoded['version'] ?? 0) !== 1) {
            throw new FederationException('Versión de identidad del nodo no soportada.', 500);
        }
        foreach (['node_id', 'public_key', 'secret_key', 'payload_key'] as $field) {
            if (!is_string($decoded[$field] ?? null) || trim((string)$decoded[$field]) === '') {
                throw new FederationException('Identidad de nodo incompleta.', 500);
            }
        }
        $public = FederationCodec::base64UrlDecode((string)$decoded['public_key']);
        $secret = FederationCodec::base64UrlDecode((string)$decoded['secret_key']);
        $payload = FederationCodec::base64UrlDecode((string)$decoded['payload_key']);
        if (strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || strlen($payload) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
            || !hash_equals(self::nodeIdFromPublicKey($public), (string)$decoded['node_id'])) {
            throw new FederationException('Identidad criptográfica del nodo inválida.', 500);
        }
        if (isset($decoded['node_name'])) {
            if (!is_string($decoded['node_name'])
                || !hash_equals(self::normalizeNodeName((string)$decoded['node_name']), (string)$decoded['node_name'])) {
                throw new FederationException('Nombre de nodo inválido dentro de la identidad.', 500);
            }
        }
        $this->identity = $decoded;
        return $decoded;
    }

    private static function writeIdentity(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new FederationException('No se pudo escribir la identidad temporal.', 500);
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new FederationException('No se pudo instalar la identidad del nodo.', 500);
        }
        @chmod($path, 0600);
    }
}
