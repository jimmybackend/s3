<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/src/Admin/DatabaseBackupService.php');
$writer = (string)file_get_contents($root . '/src/Admin/DatabaseSqlDumpWriter.php');
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
$assert(str_contains($service, '@unlink($tmp)'), 'temporary SQL is removed');
$assert(str_contains($service, 'DatabaseSqlDumpWriter'), 'backup delegates SQL generation to verified writer');
$assert(str_contains($service, "verified_complete"), 'backup requires complete verification before upload');
$assert(str_contains($service, 'rootForUser($userId)'), 'backup resolves the executing superadmin storage root');
$assert(str_contains($service, "/Backup/"), 'backup is stored in the Backup folder');
$assert(str_contains($service, "ensureFolder("), 'Backup folder is registered in the Drive catalog');
$assert(str_contains($service, "singleUploadService()->upload("), 'backup uses the normal private Drive upload service');
$assert(str_contains($service, "'application/sql'"), 'backup is catalogued as SQL');

$assert(str_contains($writer, "SELECT DATABASE()"), 'writer targets the active database');
$assert(str_contains($writer, "START TRANSACTION WITH CONSISTENT SNAPSHOT"), 'writer uses a consistent transactional snapshot');
$assert(str_contains($writer, "SHOW CREATE TABLE"), 'writer exports final table DDL including keys and constraints');
$assert(str_contains($writer, "SHOW CREATE VIEW"), 'writer exports views');
$assert(str_contains($writer, "SHOW CREATE ' . $type"), 'writer exports routines, triggers and events');
$assert(str_contains($writer, "information_schema.ROUTINES"), 'writer inventories procedures and functions');
$assert(str_contains($writer, "information_schema.TRIGGERS"), 'writer inventories triggers');
$assert(str_contains($writer, "information_schema.EVENTS"), 'writer inventories events');
$assert(str_contains($writer, "SELECT COUNT(*) FROM"), 'writer verifies per-table row counts');
$assert(str_contains($writer, "Rows verified"), 'dump records row verification');
$assert(str_contains($writer, "-- Status: COMPLETE"), 'dump has an explicit complete marker');
$assert(str_contains($writer, "hash_file('sha256'"), 'writer verifies final dump hash');
$assert(str_contains($writer, "engine !== 'INNODB'"), 'writer refuses false consistent snapshots for non-transactional tables');
$assert(!str_contains($service . $writer, 'mysqldump'), 'backup does not expose credentials through mysqldump invocation');
$assert(!str_contains($service . $writer, 'shell_exec'), 'backup does not execute an arbitrary shell');

$assert(str_contains($endpoint, 'DatabaseBackupController'), 'public endpoint is a thin controller entrypoint');
$assert(str_contains($js, 'databaseBackupCard()'), 'Mi nodo renders a database-backup card');
$assert(str_contains($js, "root.append(this.databaseBackupCard())"), 'database backup is appended at the bottom of superadmin Mi nodo');
$assert(str_contains($js, "this.config.superadmin !== true"), 'client also guards the action to superadmin mode');
$assert(str_contains($js, "database-backup.php"), 'Mi nodo calls the dedicated backup endpoint');
$assert(str_contains($js, "X-Server-Admin-CSRF"), 'browser sends server-admin CSRF');

fwrite(STDOUT, "Database backup contract smoke passed.\n");
