<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/src/Admin/DatabaseBackupService.php');
$controller = (string)file_get_contents($root . '/src/Http/Controller/DatabaseBackupController.php');
$endpoint = (string)file_get_contents($root . '/database-backup.php');
$js = (string)file_get_contents($root . '/js/so-node.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($controller, 'isSuperAdmin()'), 'endpoint enforces superadmin');
$assert(str_contains($controller, 'HTTP_X_SERVER_ADMIN_CSRF'), 'endpoint enforces server-admin CSRF');
$assert(str_contains($controller, 'SuperAdminReauthenticationService'), 'endpoint reuses superadmin reauthentication');
$assert(str_contains($controller, "requirePost()"), 'endpoint is POST-only');

$assert(str_contains($service, "tempnam(sys_get_temp_dir()"), 'backup uses non-public temporary file');
$assert(str_contains($service, "finally"), 'backup always has cleanup path');
$assert(str_contains($service, "@unlink($tmp)"), 'temporary SQL is removed');
$assert(str_contains($service, "SELECT DATABASE()"), 'backup targets the active database');
$assert(str_contains($service, "SHOW FULL TABLES"), 'backup enumerates the active schema');
$assert(str_contains($service, "SHOW CREATE TABLE"), 'backup exports table definitions');
$assert(str_contains($service, "START TRANSACTION WITH CONSISTENT SNAPSHOT"), 'backup uses a consistent transactional snapshot');
$assert(str_contains($service, "rootForUser($userId)"), 'backup resolves the executing superadmin storage root');
$assert(str_contains($service, "/Backup/"), 'backup is stored in the Backup folder');
$assert(str_contains($service, "ensureFolder("), 'Backup folder is registered in the Drive catalog');
$assert(str_contains($service, "singleUploadService()->upload("), 'backup uses the normal private Drive upload service');
$assert(str_contains($service, "'application/sql'"), 'backup is catalogued as SQL');
$assert(!str_contains($service, 'mysqldump'), 'backup does not expose credentials through mysqldump invocation');
$assert(!str_contains($service, 'shell_exec'), 'backup does not execute an arbitrary shell');

$assert(str_contains($endpoint, 'DatabaseBackupController'), 'public endpoint is a thin controller entrypoint');
$assert(str_contains($js, 'databaseBackupCard()'), 'Mi nodo renders a database-backup card');
$assert(str_contains($js, "root.append(this.databaseBackupCard())"), 'database backup is appended at the bottom of superadmin Mi nodo');
$assert(str_contains($js, "this.config.superadmin !== true"), 'client also guards the action to superadmin mode');
$assert(str_contains($js, "database-backup.php"), 'Mi nodo calls the dedicated backup endpoint');
$assert(str_contains($js, "X-Server-Admin-CSRF"), 'browser sends server-admin CSRF');

fwrite(STDOUT, "Database backup contract smoke passed.\n");
