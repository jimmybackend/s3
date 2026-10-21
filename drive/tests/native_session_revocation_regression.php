<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Security/SessionManager.php';
use ArcadeCloud\Drive\Security\SessionManager;
$checks = 0;
function nativeSessionCheck(bool $value): void { global $checks; if (!$value) throw new RuntimeException('Session assertion failed'); $checks++; }
$current = true; $calls = 0;
$session = new SessionManager(static function(string $email, int $id, string $fingerprint) use (&$current, &$calls): bool {
    $calls++;
    nativeSessionCheck($email === 'native@example.test' && $id === 2 && $fingerprint === 'fingerprint');
    return $current;
});
$session->start(); $session->establishAuthenticatedUser('native@example.test',2,'user');
$session->set('native_issued_at',time()); $session->set('native_last_seen',time());
$session->set('native_credential_fingerprint','fingerprint');
nativeSessionCheck($session->isAuthenticated());
$current = false;
nativeSessionCheck(!$session->isAuthenticated()); // Revoked password/account: shared endpoint guard denies.
nativeSessionCheck($calls === 2);
$session->set('native_last_seen',time()-1801);
nativeSessionCheck(!$session->isAuthenticated());
$session->set('native_last_seen',time());
$session->set('native_issued_at',time()-43201);
nativeSessionCheck(!$session->isAuthenticated());
$session->set('native_issued_at',time());
nativeSessionCheck(!(new SessionManager())->isAuthenticated()); // No injected verifier: fail closed.
$session->remove('native_issued_at');
nativeSessionCheck((new SessionManager())->isAuthenticated()); // Existing web session behavior unaffected.
echo "native_session_revocation_regression: $checks assertions passed\n";
