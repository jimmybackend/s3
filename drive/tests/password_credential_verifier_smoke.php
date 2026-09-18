<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Security/PasswordCredentialVerifier.php';

use ArcadeCloud\Drive\Security\PasswordCredentialVerifier;

$hashedCases = ['1', '123456', 'mama', 'AbC123', 'a!2#'];
foreach ($hashedCases as $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        fwrite(STDERR, "Could not build test password hash.\n");
        exit(1);
    }

    $result = PasswordCredentialVerifier::verify($password, $hash);
    if (($result['valid'] ?? false) !== true || ($result['migrate_plaintext'] ?? true) !== false) {
        fwrite(STDERR, "Hashed simple password was rejected: {$password}.\n");
        exit(1);
    }
}

$legacyCases = ['1', '123456', 'mama', 'AbC123', 'a!2#'];
foreach ($legacyCases as $password) {
    $result = PasswordCredentialVerifier::verify($password, $password);
    if (($result['valid'] ?? false) !== true || ($result['migrate_plaintext'] ?? false) !== true) {
        fwrite(STDERR, "Legacy simple password was rejected: {$password}.\n");
        exit(1);
    }
}

$wrongHash = password_hash('123456', PASSWORD_DEFAULT);
if (!is_string($wrongHash) || PasswordCredentialVerifier::verify('654321', $wrongHash)['valid'] !== false) {
    fwrite(STDERR, "Wrong hashed password was accepted.\n");
    exit(1);
}

if (PasswordCredentialVerifier::verify('654321', '123456')['valid'] !== false) {
    fwrite(STDERR, "Wrong legacy plaintext password was accepted.\n");
    exit(1);
}

echo "password credential verifier smoke: OK\n";
