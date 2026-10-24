<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Security/LoginRateLimiter.php';
use ArcadeCloud\Drive\Security\LoginRateLimiter;

function checkLogin(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$directory = sys_get_temp_dir() . '/login-lockout-test-' . bin2hex(random_bytes(6));
$now = 2000000000;
$limiter = new LoginRateLimiter($directory, static function () use (&$now): int { return $now; });
$email = 'person@example.test'; $ip = '203.0.113.42';
try {
    checkLogin($limiter->status($email, $ip)['remaining_attempts'] === 5, 'fresh account has five attempts');
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $limiter->registerFailure($email, $ip);
        checkLogin($limiter->allow($email, $ip), 'first four failures permit retry');
        checkLogin($limiter->status($email, $ip)['remaining_attempts'] === 5 - $attempt, 'accurate attempts remaining');
    }
    $now += 899;
    $limiter->registerFailure(strtoupper($email), $ip);
    $status = $limiter->status($email, $ip);
    checkLogin($status['blocked'] && $status['retry_after'] === 900, 'fifth failure locks for full 15 minutes even late in window');
    $now += 100;
    $limiter->registerFailure($email, $ip);
    checkLogin($limiter->status($email, $ip)['retry_after'] === 800, 'retries do not extend block');
    checkLogin(!(new LoginRateLimiter($directory, static fn(): int => $now))->allow($email, $ip), 'new instance cannot bypass persisted block');
    checkLogin($limiter->allow($email, '203.0.113.43'), 'account/IP block does not lock unrelated addresses');
    $now += 799;
    checkLogin(!$limiter->allow($email, $ip), 'still blocked one second before expiry');
    $now++;
    checkLogin($limiter->allow($email, $ip), 'allowed at exact deadline');
    checkLogin($limiter->status($email, $ip)['remaining_attempts'] === 5, 'full attempts restored after deadline');
    $limiter->registerFailure($email, $ip); $limiter->clear($email, $ip);
    checkLogin($limiter->status($email, $ip)['remaining_attempts'] === 5, 'successful authentication clears pair');
    $otherIp = '203.0.113.44';
    for ($i = 0; $i < 30; $i++) $limiter->registerFailure("user{$i}@example.test", $otherIp);
    checkLogin(!$limiter->allow('fresh@example.test', $otherIp), '30 failures block all new accounts from IP');
    checkLogin($limiter->status('fresh@example.test', $otherIp)['remaining_attempts'] === 0, 'IP blocked status offers zero attempts');
    $now += 900;
    checkLogin($limiter->allow('fresh@example.test', $otherIp), 'IP block expires');
    $legacyKey = $directory . '/pair-' . hash('sha256', "legacy@example.test\n203.0.113.46") . '.json';
    file_put_contents($legacyKey, json_encode(['first_at' => $now - 100, 'count' => 5]));
    checkLogin($limiter->status('legacy@example.test', '203.0.113.46')['retry_after'] === 800, 'legacy limiter files retain original deadline');
    if (function_exists('pcntl_fork')) {
        $children = [];
        for ($worker = 0; $worker < 8; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $limiter->synchronized('203.0.113.47', function () use ($limiter, $directory): void {
                    if ($limiter->allow('parallel@example.test', '203.0.113.47')) {
                        file_put_contents($directory . '/accepted', "attempt\n", FILE_APPEND);
                        $limiter->registerFailure('parallel@example.test', '203.0.113.47');
                    }
                });
                exit(0);
            }
            checkLogin($pid > 0, 'fork succeeds');
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $exitStatus);
            checkLogin(pcntl_wexitstatus($exitStatus) === 0, 'parallel worker exits successfully');
        }
        checkLogin(count(file($directory . '/accepted')) === 5, 'parallel submissions cannot exceed fifth failure');
    }
    $corrupt = $directory . '/ip-' . hash('sha256', '203.0.113.45') . '.json';
    file_put_contents($corrupt, '{broken');
    $failed = false;
    try { $limiter->allow($email, '203.0.113.45'); } catch (Throwable) { $failed = true; }
    checkLogin($failed, 'corrupt limiter fails closed');
    echo "login lockout regression: ok\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) unlink($path);
    @rmdir($directory);
}
