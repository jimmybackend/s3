<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Sync/SyncSchemaMigrator.php';

use ArcadeCloud\Drive\Sync\SyncSchemaMigrator;

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$user = getenv('TEST_DB_USER') ?: 'root';
$pass = getenv('TEST_DB_PASSWORD') ?: '';
$name = getenv('TEST_DB_NAME') ?: 'arcade_test';

$db = new mysqli($host, $user, $pass, $name, $port);
if ($db->connect_errno) {
    fwrite(STDERR, "DB connect failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$db->query('DROP TABLE IF EXISTS FileS3');
$sql = "CREATE TABLE FileS3 (
    id_ INT NOT NULL AUTO_INCREMENT,
    Nombre VARCHAR(255) NOT NULL,
    Encriptado VARCHAR(255) NOT NULL,
    Tamano BIGINT NOT NULL DEFAULT 0,
    Metadatos MEDIUMTEXT NULL,
    Ruta VARCHAR(256) NOT NULL,
    Found TINYINT(1) NOT NULL DEFAULT 0,
    AccessType ENUM('normal','secure','unlocked') NOT NULL DEFAULT 'normal',
    Fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id_ INT NOT NULL,
    PRIMARY KEY (id_),
    UNIQUE KEY uq_files3_user_key (user_id_, Encriptado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$db->query($sql)) {
    throw new RuntimeException($db->error);
}

$db->query("INSERT INTO FileS3 (Nombre,Encriptado,Ruta,user_id_) VALUES ('A','manifest.json','Data/a/',1)");

$result = (new SyncSchemaMigrator($db))->migrate();
if (empty($result['ok'])) {
    throw new RuntimeException('Migration did not return ok.');
}

if (!$db->query("INSERT INTO FileS3 (Nombre,Encriptado,Ruta,user_id_) VALUES ('B','manifest.json','Data/b/',1)")) {
    throw new RuntimeException('Same basename in another route must be allowed: ' . $db->error);
}

$duplicateSameRoute = $db->query("INSERT INTO FileS3 (Nombre,Encriptado,Ruta,user_id_) VALUES ('C','manifest.json','Data/a/',1)");
if ($duplicateSameRoute !== false) {
    throw new RuntimeException('Same user+route+basename must remain unique.');
}

$again = (new SyncSchemaMigrator($db))->migrate();
if (empty($again['ok']) || !empty($again['changed'])) {
    throw new RuntimeException('Migration must be idempotent.');
}

echo "OK sync schema migrator\n";
