<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use JsonException;

final class NodeIdentityBackupService
{
    private const FORMAT = 'arcadecloud-federation-node-backup';
    private const VERSION = 1;
    private const MAX_BACKUP_BYTES = 131072;

    public static function exportEncrypted(string $identityPath, string $backupPath, string $passphrase, bool $overwrite = false): array
    {
        self::requireSodium();
        self::validatePassphrase($passphrase);
        if ($backupPath === '' || $backupPath[0] !== '/') {
            throw new FederationException('La ruta del respaldo debe ser absoluta.', 400);
        }
        if (is_file($backupPath) && !$overwrite) {
            throw new FederationException('El respaldo ya existe; no se sobrescribió.', 409);
        }

        $identity = new NodeIdentityService($identityPath);
        $nodeId = $identity->nodeId();
        $nodeName = $identity->nodeName();
        $raw = @file_get_contents($identityPath);
        if (!is_string($raw) || $raw === '') {
            throw new FederationException('No se pudo leer la identidad para respaldarla.', 500);
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $opslimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
        $memlimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $passphrase,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $aad = self::aad($nodeId);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($raw, $aad, $nonce, $key);
        sodium_memzero($key);

        $backup = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'node_id' => $nodeId,
            'node_name' => $nodeName,
            'kdf' => [
                'alg' => 'Argon2id13',
                'opslimit' => $opslimit,
                'memlimit' => $memlimit,
                'salt' => FederationCodec::base64UrlEncode($salt),
            ],
            'cipher' => [
                'alg' => 'XChaCha20-Poly1305',
                'nonce' => FederationCodec::base64UrlEncode($nonce),
                'ciphertext' => FederationCodec::base64UrlEncode($ciphertext),
            ],
            'created_at' => gmdate(DATE_ATOM),
        ];

        self::writeJson($backupPath, $backup, $overwrite);
        return [
            'node_id' => $nodeId,
            'node_name' => $nodeName,
            'backup_path' => $backupPath,
        ];
    }

    public static function restoreEncrypted(string $backupPath, string $identityPath, string $passphrase, bool $overwrite = false): array
    {
        self::requireSodium();
        self::validatePassphrase($passphrase);
        if ($identityPath === '' || $identityPath[0] !== '/') {
            throw new FederationException('La ruta de identidad debe ser absoluta.', 400);
        }
        if (is_file($identityPath) && !$overwrite) {
            throw new FederationException('La identidad del nodo ya existe; no se sobrescribió.', 409);
        }

        $backup = self::readBackup($backupPath);
        $nodeId = (string)$backup['node_id'];
        $kdf = $backup['kdf'];
        $cipher = $backup['cipher'];

        $salt = FederationCodec::base64UrlDecode((string)$kdf['salt']);
        $nonce = FederationCodec::base64UrlDecode((string)$cipher['nonce']);
        $ciphertext = FederationCodec::base64UrlDecode((string)$cipher['ciphertext']);
        if (strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
            || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new FederationException('Respaldo FederationCloud corrupto.', 400);
        }

        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            self::aad($nodeId),
            $nonce,
            $key
        );
        sodium_memzero($key);
        if (!is_string($plaintext) || $plaintext === '') {
            throw new FederationException('No se pudo descifrar el respaldo FederationCloud; verifica la frase de recuperación.', 400);
        }

        $directory = dirname($identityPath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new FederationException('No se pudo crear el directorio de identidad restaurada.', 500);
        }
        $tmp = $identityPath . '.restore-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $plaintext, LOCK_EX) === false) {
            throw new FederationException('No se pudo escribir la identidad restaurada temporal.', 500);
        }
        @chmod($tmp, 0600);

        try {
            $identity = new NodeIdentityService($tmp);
            if (!hash_equals($nodeId, $identity->nodeId())) {
                throw new FederationException('El respaldo no corresponde al Node ID declarado.', 400);
            }
            $backupName = is_string($backup['node_name'] ?? null) ? (string)$backup['node_name'] : null;
            if ($backupName !== $identity->nodeName()) {
                throw new FederationException('El nombre del nodo no coincide con el respaldo.', 400);
            }
            if (!@rename($tmp, $identityPath)) {
                throw new FederationException('No se pudo instalar la identidad restaurada.', 500);
            }
            @chmod($identityPath, 0600);
            return [
                'node_id' => $identity->nodeId(),
                'node_name' => $identity->nodeName(),
                'identity_path' => $identityPath,
            ];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function readBackup(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new FederationException('El respaldo FederationCloud no existe o no es legible.', 404);
        }
        $size = @filesize($path);
        if (is_int($size) && ($size <= 0 || $size > self::MAX_BACKUP_BYTES)) {
            throw new FederationException('Tamaño de respaldo FederationCloud inválido.', 400);
        }
        try {
            $decoded = json_decode((string)file_get_contents($path), true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('El respaldo FederationCloud no contiene JSON válido.', 400);
        }
        if (!is_array($decoded)
            || ($decoded['format'] ?? null) !== self::FORMAT
            || (int)($decoded['version'] ?? 0) !== self::VERSION
            || !is_string($decoded['node_id'] ?? null)
            || !preg_match('/\Aacn_[A-Za-z0-9_-]{20,64}\z/', (string)$decoded['node_id'])
            || !is_array($decoded['kdf'] ?? null)
            || !is_array($decoded['cipher'] ?? null)
            || ($decoded['kdf']['alg'] ?? null) !== 'Argon2id13'
            || (int)($decoded['kdf']['opslimit'] ?? 0) !== SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE
            || (int)($decoded['kdf']['memlimit'] ?? 0) !== SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE
            || !is_string($decoded['kdf']['salt'] ?? null)
            || ($decoded['cipher']['alg'] ?? null) !== 'XChaCha20-Poly1305'
            || !is_string($decoded['cipher']['nonce'] ?? null)
            || !is_string($decoded['cipher']['ciphertext'] ?? null)) {
            throw new FederationException('Formato de respaldo FederationCloud inválido.', 400);
        }
        if ($decoded['node_name'] !== null) {
            if (!is_string($decoded['node_name'])
                || !hash_equals(NodeIdentityService::normalizeNodeName((string)$decoded['node_name']), (string)$decoded['node_name'])) {
                throw new FederationException('Nombre de nodo inválido en el respaldo FederationCloud.', 400);
            }
        }
        return $decoded;
    }

    private static function writeJson(string $path, array $data, bool $overwrite): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new FederationException('No se pudo crear el directorio del respaldo FederationCloud.', 500);
        }
        if (is_file($path) && !$overwrite) {
            throw new FederationException('El respaldo ya existe; no se sobrescribió.', 409);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new FederationException('No se pudo escribir el respaldo FederationCloud.', 500);
        }
        @chmod($path, 0600);
    }

    private static function validatePassphrase(string $passphrase): void
    {
        if (strlen($passphrase) < 16) {
            throw new FederationException('La frase de recuperación debe tener al menos 16 caracteres.', 400);
        }
    }

    private static function requireSodium(): void
    {
        if (!extension_loaded('sodium')) {
            throw new FederationException('La extensión sodium de PHP es obligatoria para respaldo FederationCloud.', 500);
        }
    }

    private static function aad(string $nodeId): string
    {
        return self::FORMAT . ':v' . self::VERSION . ':' . $nodeId;
    }
}
