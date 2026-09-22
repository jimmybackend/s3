<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Setup/SetupEntryGuard.php';

use ArcadeCloud\Drive\Setup\SetupEntryGuard;

$dir = sys_get_temp_dir() . '/arcadecloud-entry-guard-' . bin2hex(random_bytes(6));
$auth = $dir . '/bootstrap-auth.json';
$lock = $dir . '/setup.lock';

mkdir($dir, 0700, true);

$guard = new SetupEntryGuard($auth, $lock);

if ($guard->isSetupPending()) {
    fwrite(STDERR, "Guard should be inactive without bootstrap auth.\n");
    exit(1);
}

file_put_contents($auth, "{}\n");
if (!$guard->isSetupPending()) {
    fwrite(STDERR, "Guard should be active while bootstrap exists and setup is unlocked.\n");
    exit(1);
}

file_put_contents($lock, "{}\n");
if ($guard->isSetupPending()) {
    fwrite(STDERR, "Guard should be inactive after setup.lock exists.\n");
    exit(1);
}

@unlink($lock);
@unlink($auth);
@rmdir($dir);

echo "setup entry guard smoke: OK\n";
