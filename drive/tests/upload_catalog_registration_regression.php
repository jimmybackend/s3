<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/UploadCatalogRepository.php';

use ArcadeCloud\Drive\Upload\UploadCatalogRepository;

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

$db->query('DROP TABLE IF EXISTS FileS3');
$db->query('DROP TABLE IF EXISTS S3Folders');

$db->query("CREATE TABLE FileS3 (
    id_ INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(255) NOT NULL,
    Encriptado VARCHAR(255) NOT NULL,
    Tamano BIGINT NOT NULL DEFAULT 0,
    Metadatos MEDIUMTEXT NULL,
    Ruta VARCHAR(256) NOT NULL,
    Found TINYINT(1) NOT NULL DEFAULT 0,
    AccessType VARCHAR(32) NOT NULL DEFAULT 'normal',
    PasswordHash VARCHAR(255) NULL,
    SecureHint VARCHAR(255) NULL,
    SecureUpdatedAt DATETIME NULL,
    Fecha DATETIME NOT NULL,
    user_id_ INT NOT NULL,
    UNIQUE KEY uq_files3_user_path_key (user_id_, Ruta, Encriptado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE S3Folders (
    id_ INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id_ INT NOT NULL,
    Prefix VARCHAR(512) NOT NULL,
    Nombre VARCHAR(255) NOT NULL,
    ParentPrefix VARCHAR(512) NULL,
    Found TINYINT(1) NOT NULL DEFAULT 0,
    AccessType VARCHAR(32) NOT NULL DEFAULT 'normal',
    CreatedAt DATETIME NOT NULL,
    UpdatedAt DATETIME NOT NULL,
    UNIQUE KEY uq_s3folders_user_prefix (user_id_, Prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$repo = new UploadCatalogRepository($db);
$repo->ensureFolder(1, 'Data/uploads/', 'uploads', 'Data/');
$repo->upsertCompletedMultipart(
    1,
    'informe.pdf',
    'physical-informe.pdf',
    12345,
    '{"source":"up.php"}',
    'Data/uploads/',
    '2026-09-12 18:30:45'
);

$result = $db->query("SELECT Nombre,Encriptado,Tamano,Ruta,Found,AccessType,DATE_FORMAT(Fecha,'%Y-%m-%d %H:%i:%s') AS Fecha FROM FileS3 WHERE user_id_=1");
$row = $result->fetch_assoc();

if (!$row) {
    throw new RuntimeException('FileS3 row was not created.');
}
if ((string)$row['Nombre'] !== 'informe.pdf') {
    throw new RuntimeException('Visible name was not persisted.');
}
if ((string)$row['Encriptado'] !== 'physical-informe.pdf') {
    throw new RuntimeException('Physical basename was not persisted.');
}
if ((string)$row['Ruta'] !== 'Data/uploads/' || (int)$row['Found'] !== 1) {
    throw new RuntimeException('Upload route/found state is incorrect.');
}
if ((string)$row['Fecha'] !== '2026-09-12 18:30:45') {
    throw new RuntimeException('Explicit upload date was not persisted.');
}

$folder = $db->query("SELECT Prefix,Nombre,ParentPrefix,Found FROM S3Folders WHERE user_id_=1 AND Prefix='Data/uploads/'")->fetch_assoc();
if (!$folder) {
    throw new RuntimeException('uploads folder was not registered.');
}
if ((string)$folder['Nombre'] !== 'uploads' || (string)$folder['ParentPrefix'] !== 'Data/' || (int)$folder['Found'] !== 1) {
    throw new RuntimeException('uploads folder metadata is incorrect.');
}

$repo->upsertCompletedMultipart(
    1,
    'informe-renombrado.pdf',
    'physical-informe.pdf',
    54321,
    '{"source":"up.php","retry":true}',
    'Data/uploads/',
    '2026-09-12 19:00:00'
);

$count = (int)$db->query("SELECT COUNT(*) FROM FileS3 WHERE user_id_=1 AND Ruta='Data/uploads/' AND Encriptado='physical-informe.pdf'")->fetch_row()[0];
if ($count !== 1) {
    throw new RuntimeException('Multipart retry duplicated FileS3 row.');
}

$updated = $db->query("SELECT Nombre,Tamano,DATE_FORMAT(Fecha,'%Y-%m-%d %H:%i:%s') FROM FileS3 WHERE user_id_=1 AND Ruta='Data/uploads/' AND Encriptado='physical-informe.pdf'")->fetch_row();
if ((string)$updated[0] !== 'informe-renombrado.pdf' || (int)$updated[1] !== 54321 || (string)$updated[2] !== '2026-09-12 19:00:00') {
    throw new RuntimeException('Multipart upsert did not update catalog data/date.');
}

echo "OK upload catalog registration regression\n";
