<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

final class PasswordCredentialVerifier
{
    /**
     * @return array{valid: bool, migrate_plaintext: bool}
     */
    public static function verify(string $candidate, string $stored): array
    {
        if ($stored === '') {
            return ['valid' => false, 'migrate_plaintext' => false];
        }

        if (password_verify($candidate, $stored)) {
            return ['valid' => true, 'migrate_plaintext' => false];
        }

        $info = password_get_info($stored);
        $recognizedHash = (string)($info['algoName'] ?? 'unknown') !== 'unknown';

        if ($recognizedHash) {
            return ['valid' => false, 'migrate_plaintext' => false];
        }

        if (hash_equals($stored, $candidate)) {
            return ['valid' => true, 'migrate_plaintext' => true];
        }

        return ['valid' => false, 'migrate_plaintext' => false];
    }
}
