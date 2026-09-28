<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use mysqli;
use RuntimeException;

final class OfficeSchemaMigrationService
{
    private const START_MARKER = '-- ARCADECLOUD:OFFICE_SCHEMA:BEGIN';
    private const END_MARKER = '-- ARCADECLOUD:OFFICE_SCHEMA:END';

    private const REQUIRED_TABLES = [
        'OfficeLaunchTokens',
        'OfficeSessionLeases',
        'OfficeDocumentSessions',
    ];

    public function __construct(
        private mysqli $db,
        private ?string $schemaPath = null
    ) {
        $this->schemaPath ??= dirname(__DIR__, 3) . '/adbbmis1_Cloud.sql';
    }

    public function status(): array
    {
        $missing = $this->missingTables();
        return [
            'ok' => $missing === [],
            'ready' => $missing === [],
            'missing' => $missing,
            'required_count' => count(self::REQUIRED_TABLES),
        ];
    }

    public function ensure(): array
    {
        $status = $this->status();
        if (($status['ready'] ?? false) === true) {
            return $status + [
                'reconciled' => false,
                'message' => 'Esquema ArcadeCloud Office preparado.',
            ];
        }

        return $this->reconcile();
    }

    public function reconcile(): array
    {
        $sql = $this->officeSql();

        if (!$this->db->multi_query($sql)) {
            throw new RuntimeException('Error migrando ArcadeCloud Office: ' . $this->db->error);
        }

        do {
            if ($result = $this->db->store_result()) {
                $result->free();
            }
            if (!$this->db->more_results()) break;
        } while ($this->db->next_result());

        if ($this->db->errno) {
            throw new RuntimeException('Error completando migración ArcadeCloud Office: ' . $this->db->error);
        }

        $status = $this->status();
        if (($status['ready'] ?? false) !== true) {
            throw new RuntimeException(
                'Migración Office incompleta; faltan tablas: '
                . implode(', ', array_map('strval', $status['missing'] ?? []))
            );
        }

        return $status + [
            'reconciled' => true,
            'message' => 'Esquema ArcadeCloud Office completo instalado/actualizado desde adbbmis1_Cloud.sql.',
        ];
    }

    private function officeSql(): string
    {
        $path = (string)$this->schemaPath;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('No se encontró el SQL canónico ArcadeCloud: ' . $path);
        }

        $content = (string)file_get_contents($path);
        $start = strpos($content, self::START_MARKER);
        $end = $start === false
            ? false
            : strpos($content, self::END_MARKER, $start + strlen(self::START_MARKER));

        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('El SQL canónico no contiene la sección ArcadeCloud Office marcada.');
        }

        $sqlStart = $start + strlen(self::START_MARKER);
        $sql = trim(substr($content, $sqlStart, $end - $sqlStart));
        if ($sql === '') {
            throw new RuntimeException('La sección ArcadeCloud Office del SQL canónico está vacía.');
        }

        return $sql;
    }

    private function missingTables(): array
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo preparar la verificación del esquema Office: ' . $this->db->error
            );
        }

        $missing = [];
        foreach (self::REQUIRED_TABLES as $table) {
            $stmt->bind_param('s', $table);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException('No se pudo verificar la tabla ' . $table . ': ' . $error);
            }
            $stmt->store_result();
            if ($stmt->num_rows !== 1) {
                $missing[] = $table;
            }
            $stmt->free_result();
        }
        $stmt->close();

        return $missing;
    }
}
