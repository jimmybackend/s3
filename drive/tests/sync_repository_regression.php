<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Sync/SyncRepository.php';

use ArcadeCloud\Drive\Sync\SyncRepository;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$user = getenv('TEST_DB_USER') ?: 'root';
$pass = getenv('TEST_DB_PASSWORD') ?: '';
$name = getenv('TEST_DB_NAME') ?: 'arcadecloud_test';

$db = new mysqli($host, $user, $pass, $name, $port);
$db->set_charset('utf8mb4');

$db->query('DROP TABLE IF EXISTS S3SyncSeen');
$db->query('DROP TABLE IF EXISTS FileS3');
$db->query('DROP TABLE IF EXISTS S3Folders');

check((bool)$db->query("CREATE TABLE FileS3 (
    id_ INT NOT NULL AUTO_INCREMENT,
    Nombre VARCHAR(255) NOT NULL,
    Encriptado VARCHAR(255) NOT NULL,
    Tamano BIGINT NOT NULL,
    Metadatos MEDIUMTEXT NULL,
    Ruta VARCHAR(256) NOT NULL,
    Found TINYINT(1) NOT NULL DEFAULT 0,
    AccessType ENUM('normal','secure','unlocked') NOT NULL DEFAULT 'normal',
    Fecha DATETIME NULL,
    user_id_ INT NOT NULL,
    PRIMARY KEY(id_),
    UNIQUE KEY uq_files3_user_key(user_id_,Encriptado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"), 'crear FileS3');

check((bool)$db->query("CREATE TABLE S3Folders (
    id_ INT NOT NULL AUTO_INCREMENT,
    user_id_ INT NOT NULL,
    Prefix VARCHAR(1024) NOT NULL,
    Nombre VARCHAR(255) NOT NULL,
    ParentPrefix VARCHAR(1024) NULL,
    Found TINYINT(1) NOT NULL DEFAULT 0,
    AccessType VARCHAR(20) NOT NULL DEFAULT 'normal',
    CreatedAt DATETIME NULL,
    UpdatedAt DATETIME NULL,
    PRIMARY KEY(id_),
    UNIQUE KEY uq_folder_user_prefix(user_id_,Prefix(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"), 'crear S3Folders');

$repo = new SyncRepository($db);

$base = 'f_' . str_repeat('a', 32) . '-documento-prueba.txt';
$dir = 'Data/' . str_repeat('carpeta/', 25);
$key = $dir . $base;
check(strlen($key) > 255, 'la key de regresión debe superar 255 bytes');
check(strlen($dir) <= 256, 'la ruta de regresión debe caber en Ruta');

$repo->upsertFile(1, $key, 1234, 'documento-prueba.txt');
$result = $db->query('SELECT Ruta,Encriptado,Nombre,Found FROM FileS3 WHERE user_id_=1 LIMIT 1');
$row = $result ? $result->fetch_assoc() : null;
check(is_array($row), 'se insertó el archivo sincronizado');
check($row['Ruta'] === $dir, 'Ruta conserva el prefijo físico');
check($row['Encriptado'] === $base, 'Encriptado conserva sólo el nombre físico');
check($row['Nombre'] === 'documento-prueba.txt', 'Nombre conserva nombre visible');
check((int)$row['Found'] === 1, 'archivo marcado Found=1');

$syncId = str_repeat('b', 32);
$repo->markSeen($syncId, 1, 'file', $key);
$removed = $repo->finalizeSync(1, $syncId);
check((int)$removed['files_removed'] === 0, 'finalizeSync reconoce Ruta+Encriptado');

$db->query("INSERT INTO FileS3
    (Nombre,Encriptado,Tamano,Metadatos,Ruta,Found,AccessType,Fecha,user_id_)
    VALUES ('otro','f_" . str_repeat('c', 32) . "-otro.txt',5,NULL,'Data2/',1,'normal',NOW(),2)");

$syncId2 = str_repeat('d', 32);
$repo->markSeen($syncId2, 1, 'file', $key);
$repo->finalizeSync(1, $syncId2);
$countUser2 = (int)($db->query('SELECT COUNT(*) c FROM FileS3 WHERE user_id_=2')->fetch_assoc()['c'] ?? 0);
check($countUser2 === 1, 'sincronizar user 1 no altera catálogo del user 2');

fwrite(STDOUT, "OK: sync repository regression\n");
