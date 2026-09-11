<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Setup/BootstrapSetupAuth.php';

use ArcadeCloud\Drive\Setup\BootstrapSetupAuth;

$dir = sys_get_temp_dir() . '/arcadecloud-setup-' . bin2hex(random_bytes(6));
$authPath = $dir . '/bootstrap-auth.json';
$lockPath = $dir . '/setup.lock';
mkdir($dir, 0700, true);

$token = bin2hex(random_bytes(32));
$passwordHash = password_hash('arcadecloud', PASSWORD_DEFAULT);
file_put_contents($authPath, json_encode([
    'version' => 1,
    'enabled' => true,
    'username' => 'arcadecloud',
    'password_hash' => $passwordHash,
    'token_hash' => hash('sha256', $token),
], JSON_PRETTY_PRINT));

$checks = [];
try {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_id('acsetup' . bin2hex(random_bytes(8)));
    $auth = new BootstrapSetupAuth($authPath, $lockPath);

    $checks['available before lock'] = $auth->isAvailable();
    $checks['reject wrong activation token'] = !$auth->acceptActivationToken(str_repeat('0', 64));
    $checks['accept activation token'] = $auth->acceptActivationToken($token);
    $checks['csrf issued after token'] = (bool)preg_match('/\A[a-f0-9]{64}\z/', $auth->csrfToken());
    $checks['reject wrong bootstrap password'] = !$auth->login('arcadecloud', 'wrong-password', $auth->csrfToken());
    $checks['accept bootstrap login'] = $auth->login('arcadecloud', 'arcadecloud', $auth->csrfToken());
    $checks['authenticated after login'] = $auth->isAuthenticated();

    file_put_contents($lockPath, "{}\n");
    $status = $auth->status();
    $checks['lock disables setup'] = ($status['locked'] ?? false) === true
        && ($status['available'] ?? true) === false
        && ($status['authenticated'] ?? true) === false;

    foreach ($checks as $message => $ok) {
        if (!$ok) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
        fwrite(STDOUT, "OK: {$message}\n");
    }
    fwrite(STDOUT, "setup bootstrap smoke: OK\n");
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    @unlink($authPath);
    @unlink($lockPath);
    @rmdir($dir);
}
