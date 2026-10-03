<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;

/**
 * Produces a restorable logical backup of the currently selected MySQL/MariaDB database.
 *
 * The writer deliberately fails closed: an object that is inventoried but cannot be read,
 * a row-count mismatch, or a non-transactional base table prevents the dump from being
 * reported as complete.
 */
final class DatabaseSqlDumpWriter
{
    public function dump(mysqli $db, string $path): array
    {
        if ($path === '') {
            throw new RuntimeException('La ruta temporal del respaldo es obligatoria.');
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo temporal del respaldo.');
        }

        try {
            $summary = $this->writeDump($db, $handle);
            if (!fflush($handle)) {
                throw new RuntimeException('No se pudo finalizar el archivo de respaldo.');
            }
        } finally {
            fclose($handle);
        }

        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if ($size === false || $size <= 0 || !is_string($sha256) || !preg_match('/\A[a-f0-9]{64}\z/', $sha256)) {
            throw new RuntimeException('El respaldo generado no superó la verificación final.');
        }

        return $summary + [
            'size' => (int)$size,
            'sha256' => $sha256,
        ];
    }

    private function writeDump(mysqli $db, $handle): array
    {
        $database = $this->scalar($db, 'SELECT DATABASE()');
        if ($database === '') {
            throw new RuntimeException('No hay una base de datos activa para respaldar.');
        }

        $schema = $this->schemaMetadata($db, $database);
        $tables = $this->baseTables($db, $database);
        $views = $this->names($db, $database, 'VIEWS', 'TABLE_NAME');
        $routines = $this->routines($db, $database);
        $triggers = $this->names($db, $database, 'TRIGGERS', 'TRIGGER_NAME');
        $events = $this->names($db, $database, 'EVENTS', 'EVENT_NAME');

        foreach ($tables as $table) {
            $engine = strtoupper((string)($table['engine'] ?? ''));
            if ($engine !== 'INNODB') {
                throw new RuntimeException(
                    'El respaldo consistente se detuvo: la tabla ' . $table['name']
                    . ' usa el motor ' . ($engine !== '' ? $engine : 'desconocido')
                    . ', que no garantiza el snapshot transaccional requerido.'
                );
            }
        }

        $inventory = [
            'tables' => count($tables),
            'views' => count($views),
            'procedures' => count(array_filter($routines, static fn(array $r): bool => $r['type'] === 'PROCEDURE')),
            'functions' => count(array_filter($routines, static fn(array $r): bool => $r['type'] === 'FUNCTION')),
            'triggers' => count($triggers),
            'events' => count($events),
            'rows' => 0,
        ];

        $this->write($handle, "-- ArcadeCloud Drive verified logical database backup\n");
        $this->write($handle, '-- Database: ' . $database . "\n");
        $this->write($handle, '-- Server: ' . $db->server_info . "\n");
        $this->write($handle, '-- Generated UTC: ' . gmdate('Y-m-d H:i:s') . "\n");
        $this->write($handle, '-- Inventory: ' . json_encode($inventory, JSON_UNESCAPED_SLASHES) . "\n\n");

        $quotedDb = $this->identifier($database);
        $charset = $this->identifierToken((string)$schema['charset']);
        $collation = $this->identifierToken((string)$schema['collation']);
        $this->write($handle, "CREATE DATABASE IF NOT EXISTS {$quotedDb} CHARACTER SET {$charset} COLLATE {$collation};\nUSE {$quotedDb};\n\n");
        $this->write($handle, "SET @ARCADECLOUD_OLD_SQL_MODE=@@SQL_MODE;\n");
        $this->write($handle, "SET @ARCADECLOUD_OLD_TIME_ZONE=@@TIME_ZONE;\n");
        $this->write($handle, "SET @ARCADECLOUD_OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS;\n");
        $this->write($handle, "SET @ARCADECLOUD_OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS;\n");
        $this->write($handle, "SET NAMES utf8mb4;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET TIME_ZONE='+00:00';\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n");

        if (!$db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
            throw new RuntimeException('No se pudo fijar el aislamiento del respaldo: ' . $db->error);
        }
        if (!$db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')) {
            throw new RuntimeException('No se pudo iniciar el snapshot consistente: ' . $db->error);
        }

        try {
            foreach ($tables as $table) {
                $inventory['rows'] += $this->dumpTable($db, $handle, $table['name']);
            }
            if (!$db->commit()) {
                throw new RuntimeException('No se pudo cerrar el snapshot del respaldo.');
            }
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        foreach ($views as $view) {
            $this->dumpViewPlaceholder($db, $handle, $view);
        }

        foreach ($routines as $routine) {
            $this->dumpRoutine($db, $handle, $database, $routine['name'], $routine['type']);
        }

        foreach ($views as $view) {
            $this->dumpView($db, $handle, $view);
        }

        foreach ($triggers as $trigger) {
            $this->dumpProgrammableObject($db, $handle, $database, $trigger, 'TRIGGER');
        }

        foreach ($events as $event) {
            $this->dumpProgrammableObject($db, $handle, $database, $event, 'EVENT');
        }

        $this->write($handle, "\n-- ArcadeCloud verification summary\n");
        $this->write($handle, '-- Exported: ' . json_encode($inventory, JSON_UNESCAPED_SLASHES) . "\n");
        $this->write($handle, "-- Status: COMPLETE\n\n");
        $this->write($handle, "SET FOREIGN_KEY_CHECKS=@ARCADECLOUD_OLD_FOREIGN_KEY_CHECKS;\n");
        $this->write($handle, "SET UNIQUE_CHECKS=@ARCADECLOUD_OLD_UNIQUE_CHECKS;\n");
        $this->write($handle, "SET TIME_ZONE=@ARCADECLOUD_OLD_TIME_ZONE;\n");
        $this->write($handle, "SET SQL_MODE=@ARCADECLOUD_OLD_SQL_MODE;\n");

        return [
            'database' => $database,
            'inventory' => $inventory,
            'verified_complete' => true,
        ];
    }

    private function schemaMetadata(mysqli $db, string $database): array
    {
        $sql = "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
                FROM information_schema.SCHEMATA
                WHERE SCHEMA_NAME='" . $db->real_escape_string($database) . "' LIMIT 1";
        $result = $db->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('No se pudo leer la configuración de la base de datos.');
        }
        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row)) {
            throw new RuntimeException('La base de datos activa no aparece en information_schema.');
        }
        return [
            'charset' => (string)($row['DEFAULT_CHARACTER_SET_NAME'] ?? 'utf8mb4'),
            'collation' => (string)($row['DEFAULT_COLLATION_NAME'] ?? 'utf8mb4_unicode_ci'),
        ];
    }

    private function baseTables(mysqli $db, string $database): array
    {
        $sql = "SELECT TABLE_NAME, ENGINE
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA='" . $db->real_escape_string($database) . "'
                  AND TABLE_TYPE='BASE TABLE'
                ORDER BY TABLE_NAME";
        $result = $db->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('No se pudo inventariar las tablas de la base de datos.');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'name' => (string)($row['TABLE_NAME'] ?? ''),
                'engine' => (string)($row['ENGINE'] ?? ''),
            ];
        }
        $result->free();
        return array_values(array_filter($rows, static fn(array $row): bool => $row['name'] !== ''));
    }

    private function names(mysqli $db, string $database, string $informationSchemaTable, string $column): array
    {
        $allowed = [
            'VIEWS' => ['TABLE_NAME', 'TABLE_SCHEMA'],
            'TRIGGERS' => ['TRIGGER_NAME', 'TRIGGER_SCHEMA'],
            'EVENTS' => ['EVENT_NAME', 'EVENT_SCHEMA'],
        ];
        if (!isset($allowed[$informationSchemaTable]) || !in_array($column, $allowed[$informationSchemaTable], true)) {
            throw new RuntimeException('Inventario de objetos SQL no permitido.');
        }
        $schemaColumn = $allowed[$informationSchemaTable][1];
        $sql = "SELECT {$column}
                FROM information_schema.{$informationSchemaTable}
                WHERE {$schemaColumn}='" . $db->real_escape_string($database) . "'
                ORDER BY {$column}";
        $result = $db->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('No se pudo inventariar ' . strtolower($informationSchemaTable) . '.');
        }
        $names = [];
        while ($row = $result->fetch_assoc()) {
            $name = (string)($row[$column] ?? '');
            if ($name !== '') $names[] = $name;
        }
        $result->free();
        return $names;
    }

    private function routines(mysqli $db, string $database): array
    {
        $sql = "SELECT ROUTINE_NAME, ROUTINE_TYPE
                FROM information_schema.ROUTINES
                WHERE ROUTINE_SCHEMA='" . $db->real_escape_string($database) . "'
                ORDER BY ROUTINE_TYPE, ROUTINE_NAME";
        $result = $db->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('No se pudo inventariar procedimientos y funciones.');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $name = (string)($row['ROUTINE_NAME'] ?? '');
            $type = strtoupper((string)($row['ROUTINE_TYPE'] ?? ''));
            if ($name !== '' && in_array($type, ['PROCEDURE', 'FUNCTION'], true)) {
                $rows[] = ['name' => $name, 'type' => $type];
            }
        }
        $result->free();
        return $rows;
    }

    private function dumpTable(mysqli $db, $handle, string $table): int
    {
        $quoted = $this->identifier($table);
        $create = $db->query('SHOW CREATE TABLE ' . $quoted);
        if (!$create instanceof mysqli_result) {
            throw new RuntimeException('No se pudo leer la estructura de ' . $table . ': ' . $db->error);
        }
        $row = $create->fetch_row();
        $create->free();
        $sql = (string)($row[1] ?? '');
        if ($sql === '') {
            throw new RuntimeException('La estructura de ' . $table . ' está vacía.');
        }

        $expectedRows = (int)$this->scalar($db, 'SELECT COUNT(*) FROM ' . $quoted);
        $this->write($handle, "\n-- Table: {$table}\nDROP VIEW IF EXISTS {$quoted};\nDROP TABLE IF EXISTS {$quoted};\n{$sql};\n");

        $columns = [];
        $columnResult = $db->query('SHOW COLUMNS FROM ' . $quoted);
        if (!$columnResult instanceof mysqli_result) {
            throw new RuntimeException('No se pudieron leer las columnas de ' . $table . '.');
        }
        while ($column = $columnResult->fetch_assoc()) {
            $extra = strtoupper((string)($column['Extra'] ?? ''));
            if (str_contains($extra, 'GENERATED')) continue;
            $columns[] = (string)$column['Field'];
        }
        $columnResult->free();

        if (!$columns) {
            if ($expectedRows !== 0) {
                throw new RuntimeException('La tabla ' . $table . ' tiene filas pero ninguna columna exportable.');
            }
            return 0;
        }

        $selectColumns = implode(', ', array_map(fn(string $column): string => $this->identifier($column), $columns));
        $data = $db->query('SELECT ' . $selectColumns . ' FROM ' . $quoted, MYSQLI_USE_RESULT);
        if (!$data instanceof mysqli_result) {
            throw new RuntimeException('No se pudieron exportar los datos de ' . $table . '.');
        }

        $prefix = 'INSERT INTO ' . $quoted . ' (' . $selectColumns . ') VALUES ';
        $batch = [];
        $batchBytes = 0;
        $exportedRows = 0;
        while ($row = $data->fetch_row()) {
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null
                    ? 'NULL'
                    : "'" . $db->real_escape_string((string)$value) . "'";
            }
            $tuple = '(' . implode(',', $values) . ')';
            $batch[] = $tuple;
            $batchBytes += strlen($tuple);
            $exportedRows++;
            if (count($batch) >= 100 || $batchBytes >= 1024 * 1024) {
                $this->write($handle, $prefix . implode(",\n", $batch) . ";\n");
                $batch = [];
                $batchBytes = 0;
            }
        }
        $data->free();
        if ($batch) {
            $this->write($handle, $prefix . implode(",\n", $batch) . ";\n");
        }

        if ($exportedRows !== $expectedRows) {
            throw new RuntimeException(
                "Verificación fallida en {$table}: se esperaban {$expectedRows} filas y se exportaron {$exportedRows}."
            );
        }
        $this->write($handle, "-- Rows verified: {$exportedRows}\n");
        return $exportedRows;
    }

    private function dumpViewPlaceholder(mysqli $db, $handle, string $view): void
    {
        $quoted = $this->identifier($view);
        $columns = $db->query('SHOW COLUMNS FROM ' . $quoted);
        if (!$columns instanceof mysqli_result) {
            throw new RuntimeException('No se pudo preparar la vista ' . $view . ' para restauración.');
        }
        $defs = [];
        while ($column = $columns->fetch_assoc()) {
            $name = (string)($column['Field'] ?? '');
            $type = (string)($column['Type'] ?? '');
            if ($name === '' || $type === '') {
                $columns->free();
                throw new RuntimeException('La vista ' . $view . ' tiene una columna no exportable.');
            }
            $defs[] = $this->identifier($name) . ' ' . $type . ' NULL';
        }
        $columns->free();
        if (!$defs) {
            throw new RuntimeException('La vista ' . $view . ' no tiene columnas exportables.');
        }
        $this->write(
            $handle,
            "\n-- View placeholder: {$view}\nDROP VIEW IF EXISTS {$quoted};\nDROP TABLE IF EXISTS {$quoted};\nCREATE TABLE {$quoted} (" . implode(', ', $defs) . ");\n"
        );
    }

    private function dumpView(mysqli $db, $handle, string $view): void
    {
        $quoted = $this->identifier($view);
        $result = $db->query('SHOW CREATE VIEW ' . $quoted);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('No se pudo leer la definición de la vista ' . $view . ': ' . $db->error);
        }
        $row = $result->fetch_assoc();
        $result->free();
        $sql = $this->createStatement($row, 'VIEW', $view);
        $this->write($handle, "\n-- View: {$view}\nDROP TABLE IF EXISTS {$quoted};\nDROP VIEW IF EXISTS {$quoted};\n{$sql};\n");
    }

    private function dumpRoutine(mysqli $db, $handle, string $database, string $name, string $type): void
    {
        if (!in_array($type, ['PROCEDURE', 'FUNCTION'], true)) {
            throw new RuntimeException('Tipo de rutina SQL no permitido.');
        }
        $qualified = $this->identifier($database) . '.' . $this->identifier($name);
        $result = $db->query('SHOW CREATE ' . $type . ' ' . $qualified);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('No se pudo leer ' . strtolower($type) . ' ' . $name . ': ' . $db->error);
        }
        $row = $result->fetch_assoc();
        $result->free();
        $sql = $this->createStatement($row, $type, $name);
        $this->writeProgrammable($handle, $type, $name, $qualified, $sql, $row);
    }

    private function dumpProgrammableObject(
        mysqli $db,
        $handle,
        string $database,
        string $name,
        string $type
    ): void {
        if (!in_array($type, ['TRIGGER', 'EVENT'], true)) {
            throw new RuntimeException('Tipo de objeto SQL no permitido.');
        }
        $qualified = $this->identifier($database) . '.' . $this->identifier($name);
        $result = $db->query('SHOW CREATE ' . $type . ' ' . $qualified);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('No se pudo leer ' . strtolower($type) . ' ' . $name . ': ' . $db->error);
        }
        $row = $result->fetch_assoc();
        $result->free();
        $sql = $this->createStatement($row, $type, $name);
        $this->writeProgrammable($handle, $type, $name, $qualified, $sql, $row);
    }

    private function writeProgrammable($handle, string $type, string $name, string $qualified, string $sql, array $row): void
    {
        $sqlMode = isset($row['sql_mode']) ? (string)$row['sql_mode'] : '';
        $charset = isset($row['character_set_client']) ? (string)$row['character_set_client'] : '';
        $collation = isset($row['collation_connection']) ? (string)$row['collation_connection'] : '';

        $this->write($handle, "\n-- {$type}: {$name}\n");
        $this->write($handle, "SET @ARCADECLOUD_OBJECT_SQL_MODE=@@SQL_MODE;\n");
        if ($sqlMode !== '') {
            $this->write($handle, "SET SQL_MODE='" . $this->sqlLiteral($sqlMode) . "';\n");
        }
        if ($charset !== '') {
            $this->write($handle, 'SET NAMES ' . $this->identifierToken($charset));
            if ($collation !== '') {
                $this->write($handle, ' COLLATE ' . $this->identifierToken($collation));
            }
            $this->write($handle, ";\n");
        }
        $this->write($handle, "DROP {$type} IF EXISTS {$qualified};\nDELIMITER ;;\n{$sql};;\nDELIMITER ;\n");
        $this->write($handle, "SET SQL_MODE=@ARCADECLOUD_OBJECT_SQL_MODE;\n");
    }

    private function createStatement(array $row, string $type, string $name): string
    {
        $preferred = [
            'VIEW' => ['Create View'],
            'PROCEDURE' => ['Create Procedure'],
            'FUNCTION' => ['Create Function'],
            'TRIGGER' => ['SQL Original Statement', 'Create Trigger'],
            'EVENT' => ['Create Event'],
        ][$type] ?? [];

        foreach ($preferred as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && stripos($value, 'CREATE') !== false) {
                return trim($value);
            }
        }
        foreach ($row as $value) {
            if (is_string($value) && preg_match('/\bCREATE\b/i', $value)) {
                return trim($value);
            }
        }
        throw new RuntimeException('No se encontró la sentencia CREATE de ' . strtolower($type) . ' ' . $name . '.');
    }

    private function scalar(mysqli $db, string $sql): string
    {
        $result = $db->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Consulta de verificación fallida: ' . $db->error);
        }
        $row = $result->fetch_row();
        $result->free();
        return trim((string)($row[0] ?? ''));
    }

    private function identifier(string $value): string
    {
        $tick = chr(96);
        return $tick . str_replace($tick, $tick . $tick, $value) . $tick;
    }

    private function identifierToken(string $value): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $value)) {
            throw new RuntimeException('Identificador SQL inesperado en metadatos de servidor.');
        }
        return $value;
    }

    private function sqlLiteral(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    private function write($handle, string $content): void
    {
        $length = strlen($content);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($handle, substr($content, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('No se pudo escribir el respaldo de la base de datos.');
            }
            $written += $result;
        }
    }
}
