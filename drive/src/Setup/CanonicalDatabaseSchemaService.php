<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Setup;

use mysqli;
use RuntimeException;

final class CanonicalDatabaseSchemaService
{
    private const REQUIRED_FRESH_TABLES = [
        'Users',
        'FileS3',
        'DriveActivityEvents',
        'MediaProcessingJobs',
        'MediaWorkerNodeSessions',
    ];

    public function __construct(
        private string $schemaPath
    ) {
    }

    public static function fromRepository(): self
    {
        return new self(dirname(__DIR__, 3) . '/adbbmis1_Cloud.sql');
    }

    /**
     * Sólo importa el dump completo cuando la base seleccionada está vacía.
     * Una base con cualquier objeto existente jamás recibe los DROP TABLE del dump.
     */
    public function initializeIfEmpty(mysqli $db): array
    {
        $database = $this->currentDatabase($db);
        $objectsBefore = $this->objectCount($db, $database);

        if ($objectsBefore > 0) {
            return [
                'initialized' => false,
                'database' => $database,
                'objects_before' => $objectsBefore,
                'required_tables_present' => $this->requiredTablesPresent($db, $database),
            ];
        }

        if (!is_file($this->schemaPath) || !is_readable($this->schemaPath)) {
            throw new RuntimeException('No se encontró el esquema canónico adbbmis1_Cloud.sql.');
        }
        $sql = (string)file_get_contents($this->schemaPath);
        if (trim($sql) === '') {
            throw new RuntimeException('El esquema canónico está vacío.');
        }

        if (!$db->multi_query($sql)) {
            throw new RuntimeException(
                'No se pudo inicializar la base vacía con el esquema canónico: ' . $db->error
            );
        }

        do {
            $result = $db->store_result();
            if ($result !== false) {
                $result->free();
            }
            if (!$db->more_results()) {
                break;
            }
        } while ($db->next_result());

        if ($db->errno !== 0) {
            throw new RuntimeException(
                'La importación del esquema canónico terminó con error: ' . $db->error
            );
        }

        $missing = $this->missingRequiredTables($db, $database);
        if ($missing !== []) {
            throw new RuntimeException(
                'El esquema se importó, pero faltan tablas obligatorias: ' . implode(', ', $missing)
            );
        }

        return [
            'initialized' => true,
            'database' => $database,
            'objects_before' => 0,
            'required_tables_present' => true,
        ];
    }

    private function currentDatabase(mysqli $db): string
    {
        $result = $db->query('SELECT DATABASE() AS db_name');
        $row = $result?->fetch_assoc();
        $result?->free();
        $database = trim((string)($row['db_name'] ?? ''));
        if ($database === '') {
            throw new RuntimeException('La conexión MySQL no tiene una base seleccionada.');
        }
        return $database;
    }

    private function objectCount(mysqli $db, string $database): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo comprobar si la base está vacía.');
        }
        $stmt->bind_param('s', $database);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return max(0, (int)($row['total'] ?? 0));
    }

    private function requiredTablesPresent(mysqli $db, string $database): bool
    {
        return $this->missingRequiredTables($db, $database) === [];
    }

    private function missingRequiredTables(mysqli $db, string $database): array
    {
        $missing = [];
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar el esquema instalado.');
        }

        foreach (self::REQUIRED_FRESH_TABLES as $table) {
            $stmt->bind_param('ss', $database, $table);
            $stmt->execute();
            $row = $stmt->get_result()?->fetch_assoc();
            if ((int)($row['total'] ?? 0) !== 1) {
                $missing[] = $table;
            }
        }
        $stmt->close();

        return $missing;
    }
}
