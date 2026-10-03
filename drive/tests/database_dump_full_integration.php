<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Admin/DatabaseSqlDumpWriter.php';

use ArcadeCloud\Drive\Admin\DatabaseSqlDumpWriter;

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('DB_PORT') ?: '3306');
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$dumpPath = getenv('DUMP_PATH') ?: sys_get_temp_dir() . '/arcadecloud-full-dump-test.sql';
$dbName = getenv('DB_NAME') ?: 'arcade_dump_test';

$db = new mysqli($host, $user, $pass, '', $port);
if ($db->connect_errno) {
    fwrite(STDERR, "DB connect failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$tick = chr(96);
$qi = static fn(string $value): string => $tick . str_replace($tick, $tick . $tick, $value) . $tick;
$qdb = $qi($dbName);

$statements = [
    "DROP DATABASE IF EXISTS {$qdb}",
    "CREATE DATABASE {$qdb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    "USE {$qdb}",
    "CREATE TABLE parent (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(40) NOT NULL,
        payload VARCHAR(255) NULL,
        code_len INT AS (CHAR_LENGTH(code)) STORED,
        PRIMARY KEY (id),
        UNIQUE KEY uq_parent_code (code),
        KEY idx_parent_payload (payload)
    ) ENGINE=InnoDB",
    "CREATE TABLE child (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        parent_id BIGINT UNSIGNED NOT NULL,
        note TEXT NULL,
        PRIMARY KEY (id),
        KEY idx_child_parent (parent_id),
        CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES parent(id) ON DELETE CASCADE
    ) ENGINE=InnoDB",
    "INSERT INTO parent(code,payload) VALUES
        ('alpha','quote '' slash \\\\ newline\\nvalue'),
        ('beta',NULL)",
    "CREATE VIEW v_parent AS SELECT id, code, code_len FROM parent",
    "CREATE VIEW v_nested AS SELECT code, code_len FROM v_parent WHERE code_len > 0",
    "CREATE PROCEDURE p_echo(IN p_value INT) SELECT p_value AS echoed",
    "CREATE FUNCTION f_double(p_value INT) RETURNS INT DETERMINISTIC RETURN p_value * 2",
    "CREATE TRIGGER child_before_insert BEFORE INSERT ON child
       FOR EACH ROW SET NEW.note = COALESCE(NEW.note, 'from-trigger')",
    "INSERT INTO child(parent_id,note) VALUES (1,NULL),(2,'manual')",
    "CREATE EVENT e_noop ON SCHEDULE EVERY 1 DAY DO SET @arcadecloud_dump_event = 1",
];

foreach ($statements as $sql) {
    if (!$db->query($sql)) {
        fwrite(STDERR, "Setup failed: {$db->error}\nSQL: {$sql}\n");
        exit(1);
    }
}

if (!$db->select_db($dbName)) {
    fwrite(STDERR, "Cannot select test database: {$db->error}\n");
    exit(1);
}

try {
    $result = (new DatabaseSqlDumpWriter())->dump($db, $dumpPath);
} catch (Throwable $error) {
    fwrite(STDERR, "Dump failed: {$error->getMessage()}\n");
    exit(1);
}

$inventory = (array)($result['inventory'] ?? []);
$expected = [
    'tables' => 2,
    'views' => 2,
    'procedures' => 1,
    'functions' => 1,
    'triggers' => 1,
    'events' => 1,
    'rows' => 4,
];
foreach ($expected as $key => $value) {
    if ((int)($inventory[$key] ?? -1) !== $value) {
        fwrite(STDERR, "Inventory mismatch {$key}: expected {$value}, got " . ($inventory[$key] ?? 'missing') . "\n");
        exit(1);
    }
}
if (($result['verified_complete'] ?? false) !== true) {
    fwrite(STDERR, "Dump was not marked complete.\n");
    exit(1);
}
if (!is_file($dumpPath) || filesize($dumpPath) <= 0) {
    fwrite(STDERR, "Dump file missing or empty.\n");
    exit(1);
}

$content = (string)file_get_contents($dumpPath);
foreach ([
    'PRIMARY KEY',
    'UNIQUE KEY',
    'idx_parent_payload',
    'CONSTRAINT',
    'GENERATED ALWAYS',
    'AUTO_INCREMENT',
    'CREATE ALGORITHM',
    'PROCEDURE',
    'FUNCTION',
    'TRIGGER',
    'EVENT',
    '-- Status: COMPLETE',
] as $needle) {
    if (stripos($content, $needle) === false) {
        fwrite(STDERR, "Dump is missing expected SQL marker: {$needle}\n");
        exit(1);
    }
}

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
