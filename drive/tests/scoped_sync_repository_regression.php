<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Sync/SyncRepository.php';

use ArcadeCloud\Drive\Sync\SyncRepository;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$user = getenv('TEST_DB_USER') ?: 'root';
$pass = getenv('TEST_DB_PASSWORD') ?: '';
$name = getenv('TEST_DB_NAME') ?: 'arcade_test';

$db = null;
$last = null;
for ($attempt = 0; $attempt < 30; $attempt++) {
    try {
        $db = new mysqli($host, $user, $pass, $name, $port);
        break;
    } catch (mysqli_sql_exception $error) {
        $last = $error;
        usleep(500000);
    }
}
if (!$db instanceof mysqli) {
    throw new RuntimeException('DB unavailable: ' . ($last?->getMessage() ?? 'unknown'));
}
$db->set_charset('utf8mb4');

$db->query('DROP TABLE IF EXISTS S3SyncSeen');
$db->query('DROP TABLE IF EXISTS FileS3');
$db->query('DROP TABLE IF EXISTS S3Folders');

$db->query("CREATE TABLE FileS3 (
    id_ INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(255) NOT NULL,
    Encriptado VARCHAR(255) NOT NULL,
    Ruta VARCHAR(256) NOT NULL,
    user_id_ INT NOT NULL,
    UNIQUE KEY uq_files3_user_path_key (user_id_, Ruta, Encriptado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE S3Folders (
    id_ INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id_ INT NOT NULL,
    Prefix VARCHAR(512) NOT NULL,
    Nombre VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_s3folders_user_prefix (user_id_, Prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("INSERT INTO FileS3 (Nombre,Encriptado,Ruta,user_id_) VALUES
    ('keep','keep.txt','Data/a/',1),
    ('missing','missing.txt','Data/a/',1),
    ('outside','outside.txt','Data/b/',1),
    ('other-user','other.txt','Data2/a/',2)");

$db->query("INSERT INTO S3Folders (user_id_,Prefix,Nombre) VALUES
    (1,'Data/a/','a'),
    (1,'Data/a/sub/','sub'),
    (1,'Data/b/','b'),
    (2,'Data2/a/','a')");

$repo = new SyncRepository($db);
$syncId = str_repeat('a', 32);
$repo->markSeen($syncId, 1, 'file', 'Data/a/keep.txt');
$repo->markSeen($syncId, 1, 'folder', 'Data/a/');

$result = $repo->finalizeSyncPrefix(1, $syncId, 'Data/a/');

if ((int)$result['files_removed'] !== 1 || (int)$result['folders_removed'] !== 1) {
    throw new RuntimeException('Scoped removal counts are incorrect.');
}

$expectCount = static function (mysqli $db, string $sql, int $expected, string $message): void {
    $res = $db->query($sql);
    $row = $res->fetch_row();
    $actual = (int)($row[0] ?? -1);
    if ($actual !== $expected) {
        throw new RuntimeException($message . " expected=$expected actual=$actual");
    }
};

$expectCount($db, "SELECT COUNT(*) FROM FileS3 WHERE user_id_=1 AND Ruta='Data/a/' AND Encriptado='keep.txt'", 1, 'Seen file must survive');
$expectCount($db, "SELECT COUNT(*) FROM FileS3 WHERE user_id_=1 AND Ruta='Data/a/' AND Encriptado='missing.txt'", 0, 'Missing scoped file must be removed');
$expectCount($db, "SELECT COUNT(*) FROM FileS3 WHERE user_id_=1 AND Ruta='Data/b/'", 1, 'Outside folder must survive');
$expectCount($db, "SELECT COUNT(*) FROM FileS3 WHERE user_id_=2", 1, 'Other user must survive');
$expectCount($db, "SELECT COUNT(*) FROM S3Folders WHERE user_id_=1 AND Prefix='Data/a/'", 1, 'Seen folder must survive');
$expectCount($db, "SELECT COUNT(*) FROM S3Folders WHERE user_id_=1 AND Prefix='Data/a/sub/'", 0, 'Missing scoped child folder must be removed');
$expectCount($db, "SELECT COUNT(*) FROM S3Folders WHERE user_id_=1 AND Prefix='Data/b/'", 1, 'Outside folder row must survive');
$expectCount($db, "SELECT COUNT(*) FROM S3Folders WHERE user_id_=2", 1, 'Other user folder must survive');
$expectCount($db, "SELECT COUNT(*) FROM S3SyncSeen WHERE sync_id='$syncId'", 0, 'Seen staging must be cleared');

echo "OK scoped sync repository regression\n";
