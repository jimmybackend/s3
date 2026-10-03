<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Core\DriveApplication;
use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;

final class DatabaseBackupService
{
    public function __construct(private DriveApplication $app)
    {
    }

    public function createForSuperAdmin(int $userId): array
    {
        if ($userId <= 0 || !$this->app->session()->isSuperAdmin()) {
            throw new RuntimeException('Acceso reservado al superadministrador.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'arcadecloud-db-');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del respaldo.');
        }

        $filename = 'ArcadeCloud_DB_' . date('Y-m-d_His') . '.sql';
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo abrir el archivo temporal del respaldo.');
        }

        try {
            $this->writeDump($this->app->db(), $handle);
            if (!fflush($handle)) {
                throw new RuntimeException('No se pudo finalizar el archivo de respaldo.');
            }
            fclose($handle);
            $handle = null;

            $size = filesize($tmp);
            if ($size === false || $size <= 0) {
                throw new RuntimeException('El respaldo generado está vacío.');
            }

            $root = $this->app->userStoragePath()->rootForUser($userId);
            $route = rtrim($root, '/') . '/Backup/';
            $this->app->uploadCatalogRepository()->ensureFolder(
                $userId,
                $route,
                'Backup',
                $root
            );

            $uploaded = $this->app->singleUploadService()->upload(
                $tmp,
                $filename,
                $route,
                $userId,
                'application/sql',
                (int)$size,
                'server-generated',
                'ArcadeCloud database backup'
            );

            return [
                'filename' => $filename,
                'route' => $route,
                'size' => (int)$size,
                'file_id' => (int)($uploaded['id'] ?? 0),
            ];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($tmp);
        }
    }

    private function writeDump(mysqli $db, $handle): void
    {
        $database = $this->scalar($db, 'SELECT DATABASE()');
        if ($database === '') {
            throw new RuntimeException('No hay una base de datos activa para respaldar.');
        }

        $this->write($handle, "-- ArcadeCloud Drive database backup\n");
        $this->write($handle, '-- Database: ' . $database . "\n");
        $this->write($handle, '-- Generated UTC: ' . gmdate('Y-m-d H:i:s') . "\n\n");
        $this->write($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n");

        $tables = [];
        $views = [];
        $result = $db->query('SHOW FULL TABLES');
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('No se pudo enumerar el esquema de la base de datos.');
        }
        while ($row = $result->fetch_row()) {
            $name = (string)($row[0] ?? '');
            $type = strtoupper((string)($row[1] ?? ''));
            if ($name === '') continue;
            if ($type === 'VIEW') $views[] = $name;
            else $tables[] = $name;
        }
        $result->free();

        $db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        try {
            foreach ($tables as $table) {
                $this->dumpTable($db, $handle, $table);
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        foreach ($views as $view) {
            $this->dumpView($db, $handle, $view);
        }

        $this->write($handle, "\nSET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
    }

    private function dumpTable(mysqli $db, $handle, string $table): void
    {
        $quoted = $this->identifier($table);
        $create = $db->query('SHOW CREATE TABLE ' . $quoted);
        if (!$create instanceof mysqli_result) {
            throw new RuntimeException('No se pudo leer la estructura de ' . $table . '.');
        }
        $row = $create->fetch_row();
        $create->free();
        $sql = (string)($row[1] ?? '');
        if ($sql === '') {
            throw new RuntimeException('La estructura de ' . $table . ' está vacía.');
        }

        $this->write($handle, "\n-- Table: " . $table . "\nDROP TABLE IF EXISTS " . $quoted . ";\n" . $sql . ";\n");

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
        if (!$columns) return;

        $selectColumns = implode(', ', array_map(fn(string $column): string => $this->identifier($column), $columns));
        $data = $db->query('SELECT ' . $selectColumns . ' FROM ' . $quoted, MYSQLI_USE_RESULT);
        if (!$data instanceof mysqli_result) {
            throw new RuntimeException('No se pudieron exportar los datos de ' . $table . '.');
        }

        $prefix = 'INSERT INTO ' . $quoted . ' (' . $selectColumns . ') VALUES ';
        $batch = [];
        $batchBytes = 0;
        while ($row = $data->fetch_row()) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = "'" . $db->real_escape_string((string)$value) . "'";
                }
            }
            $tuple = '(' . implode(',', $values) . ')';
            $batch[] = $tuple;
            $batchBytes += strlen($tuple);
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
    }

    private function dumpView(mysqli $db, $handle, string $view): void
    {
        $quoted = $this->identifier($view);
        $result = $db->query('SHOW CREATE VIEW ' . $quoted);
        if (!$result instanceof mysqli_result) return;
        $row = $result->fetch_assoc();
        $result->free();
        $sql = (string)($row['Create View'] ?? '');
        if ($sql === '') return;
        $this->write($handle, "\n-- View: " . $view . "\nDROP VIEW IF EXISTS " . $quoted . ";\n" . $sql . ";\n");
    }

    private function scalar(mysqli $db, string $sql): string
    {
        $result = $db->query($sql);
        if (!$result instanceof mysqli_result) return '';
        $row = $result->fetch_row();
        $result->free();
        return trim((string)($row[0] ?? ''));
    }

    private function identifier(string $value): string
    {
        $tick = chr(96);
        return $tick . str_replace($tick, $tick . $tick, $value) . $tick;
    }

    private function write($handle, string $content): void
    {
        if (fwrite($handle, $content) === false) {
            throw new RuntimeException('No se pudo escribir el respaldo de la base de datos.');
        }
    }
}
