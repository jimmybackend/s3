<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Security/LoginRateLimiter.php';
require_once dirname(__DIR__) . '/upload/core/UploaderInterface.php';
require_once dirname(__DIR__) . '/upload/repositories/FileS3Repository.php';
require_once dirname(__DIR__) . '/upload/drivers/RemoteUrlUploader.php';

use ArcadeCloud\Drive\Security\LoginRateLimiter;

function securityHardeningCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$rateDir = sys_get_temp_dir() . '/arcadecloud-rate-test-' . bin2hex(random_bytes(6));
$limiter = new LoginRateLimiter($rateDir);
$email = 'family@example.test';
$ip = '203.0.113.10';

securityHardeningCheck($limiter->allow($email, $ip), 'fresh login must be allowed');
for ($i = 0; $i < 5; $i++) {
    $limiter->registerFailure($email, $ip);
}
securityHardeningCheck(!$limiter->allow($email, $ip), 'five account/IP failures must throttle');
$limiter->clear($email, $ip);
securityHardeningCheck($limiter->allow($email, $ip), 'successful login clear must restore pair access');

$reflection = new ReflectionClass(RemoteUrlUploader::class);
$instance = $reflection->newInstanceWithoutConstructor();
$safeTarget = $reflection->getMethod('safeTarget');

foreach ([
    'http://example.com/file.txt',
    'https://127.0.0.1/private',
    'https://10.0.0.1/private',
    'https://169.254.169.254/latest/meta-data/',
] as $blocked) {
    $rejected = false;
    try {
        $safeTarget->invoke($instance, $blocked);
    } catch (RuntimeException) {
        $rejected = true;
    }
    securityHardeningCheck($rejected, 'blocked SSRF target was accepted: ' . $blocked);
}

$public = $safeTarget->invoke($instance, 'https://8.8.8.8/file.txt');
securityHardeningCheck(($public[1] ?? '') === '8.8.8.8', 'public IPv4 target should remain valid');

$resolveRedirect = $reflection->getMethod('resolveRedirect');
$relative = $resolveRedirect->invoke($instance, 'https://example.com/a/b/file', '../next');
securityHardeningCheck(str_starts_with($relative, 'https://example.com/'), 'relative redirect must stay on HTTPS origin before revalidation');

echo "security hardening smoke: ok\n";
