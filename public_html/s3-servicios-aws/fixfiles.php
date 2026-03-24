<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| Auditoría y validación de FileS3 contra AWS S3 por carpetas
|--------------------------------------------------------------------------
|
| Este script revisa los registros de la tabla FileS3 comparándolos con los
| archivos reales existentes en el bucket S3, trabajando por carpetas (Ruta)
| y en bloques limitados para evitar sobrecarga.
|
| OBJETIVO
| - Verificar que cada registro de FileS3 apunte a un archivo real en S3.
| - Comprobar la key real usando la lógica del sistema:
|     - si Encriptado ya contiene la ruta completa, se usa tal cual
|     - si no, se construye como Ruta + Encriptado
| - Detectar inconsistencias entre BD y S3 sin modificar nunca los archivos
|   almacenados en S3.
|
| QUÉ HACE
| - Recorre carpetas de la tabla FileS3 por segmentos.
| - Consulta los objetos reales de cada carpeta en S3.
| - Agrupa los registros de la BD por Nombre visible.
| - Valida si cada registro realmente existe en S3 usando Ruta + Encriptado.
| - Registra el resultado en tablas auxiliares:
|     - FileS3_RepairControl
|     - FileS3_RepairLog
|
| MODOS DE TRABAJO
| - DRY RUN (setDryRun(true)):
|     Solo audita y genera logs. No cambia nada en la base de datos.
|
| - EJECUCIÓN REAL (setDryRun(false)):
|     Solo aplicaría correcciones si se detectaran acciones como:
|     - actualizar Encriptado
|     - eliminar registros duplicados de la BD
|
| RESULTADO DE ESTA REVISIÓN
| - Se revisaron 4572 registros.
| - Todos quedaron clasificados como KEEP.
| - No se detectaron registros faltantes en S3.
| - No se detectaron duplicados a eliminar.
| - No fue necesario actualizar ningún valor en la tabla FileS3.
|
| CONCLUSIÓN
| - La tabla FileS3 quedó validada contra S3.
| - Los registros existentes en la BD son coherentes con los archivos reales
|   almacenados en AWS S3.
| - Este script no elimina ni modifica archivos en S3; solo audita y, si fuera
|   necesario, corrige registros de la base de datos.
|--------------------------------------------------------------------------
*/

/*

SELECT Accion, COUNT(*) AS total
FROM FileS3_RepairLog
GROUP BY Accion
ORDER BY total DESC;

SELECT Estado, COUNT(*) AS total
FROM FileS3_RepairControl
GROUP BY Estado;

Para ver confirmación final
SELECT Accion, COUNT(*) AS total
FROM FileS3_RepairLog
GROUP BY Accion
ORDER BY total DESC;
*/


require_once __DIR__ . '/app_bootstrap.php';

use Aws\Exception\AwsException;

if (!isset($db_connection) || !$db_connection instanceof mysqli) {
    die('No existe una conexión mysqli válida en $db_connection');
}

mysqli_set_charset($db_connection, 'utf8mb4');

final class FileS3RepairByFolderV2
{
    private mysqli $db;
    private \Aws\S3\S3Client $s3;
    private string $bucket;
    private string $rootPrefix = 'Data/';
    private int $folderLimit = 100;
    private bool $dryRun = true;

    public function __construct(mysqli $db, \Aws\S3\S3Client $s3, string $bucket)
    {
        $this->db = $db;
        $this->s3 = $s3;
        $this->bucket = $bucket;
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function setFolderLimit(int $folderLimit): void
    {
        $this->folderLimit = max(1, $folderLimit);
    }

    public function run(): void
    {
        $this->ensureControlTables();

        $prefixes = $this->getPendingPrefixes($this->folderLimit);

        if (empty($prefixes)) {
            $this->seedPrefixesFromDb();
            $prefixes = $this->getPendingPrefixes($this->folderLimit);
        }

        if (empty($prefixes)) {
            $this->out("No hay carpetas pendientes.");
            return;
        }

        $this->out("Modo: " . ($this->dryRun ? 'DRY RUN' : 'EJECUCIÓN REAL'));
        $this->out("Bucket: {$this->bucket}");
        $this->out("Procesando hasta {$this->folderLimit} carpetas");
        $this->out(str_repeat('=', 90));

        foreach ($prefixes as $prefix) {
            $this->processFolder($prefix);
            $this->out(str_repeat('-', 90));
        }
    }

    private function ensureControlTables(): void
    {
        $sql1 = "
            CREATE TABLE IF NOT EXISTS FileS3_RepairControl (
                id_ INT NOT NULL AUTO_INCREMENT,
                Prefix VARCHAR(512) NOT NULL,
                Estado ENUM('pendiente','procesando','revisado','error') NOT NULL DEFAULT 'pendiente',
                UltimoMensaje TEXT NULL,
                TotalS3 INT NOT NULL DEFAULT 0,
                TotalBD INT NOT NULL DEFAULT 0,
                TotalAcciones INT NOT NULL DEFAULT 0,
                LastProcessedAt TIMESTAMP NULL DEFAULT NULL,
                CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id_),
                UNIQUE KEY uq_prefix (Prefix)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        $sql2 = "
            CREATE TABLE IF NOT EXISTS FileS3_RepairLog (
                id_ BIGINT NOT NULL AUTO_INCREMENT,
                Prefix VARCHAR(512) NOT NULL,
                FileS3_id INT NULL,
                Accion ENUM(
                    'keep',
                    'update_encriptado_fullpath',
                    'delete_duplicate_db',
                    'missing_in_s3',
                    'warning'
                ) NOT NULL,
                Nombre VARCHAR(255) NULL,
                EncriptadoAntes VARCHAR(255) NULL,
                EncriptadoDespues VARCHAR(255) NULL,
                S3Key VARCHAR(1024) NULL,
                Detalle TEXT NULL,
                CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_),
                KEY idx_prefix (Prefix(191)),
                KEY idx_file (FileS3_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        if (!$this->db->query($sql1)) {
            throw new RuntimeException('Error creando FileS3_RepairControl: ' . $this->db->error);
        }

        if (!$this->db->query($sql2)) {
            throw new RuntimeException('Error creando FileS3_RepairLog: ' . $this->db->error);
        }
    }

    private function seedPrefixesFromDb(): void
    {
        $sql = "SELECT DISTINCT Ruta FROM FileS3 WHERE Ruta IS NOT NULL AND Ruta <> '' ORDER BY Ruta ASC";
        $res = $this->db->query($sql);

        if (!$res) {
            throw new RuntimeException('Error leyendo rutas FileS3: ' . $this->db->error);
        }

        $stmt = $this->db->prepare("
            INSERT IGNORE INTO FileS3_RepairControl (Prefix, Estado)
            VALUES (?, 'pendiente')
        ");

        if (!$stmt) {
            throw new RuntimeException('Error preparando seed prefixes: ' . $this->db->error);
        }

        while ($row = $res->fetch_assoc()) {
            $prefix = $this->normalizePrefix((string)$row['Ruta']);
            $stmt->bind_param('s', $prefix);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Error insertando prefix: ' . $stmt->error);
            }
        }

        $stmt->close();
    }

    private function getPendingPrefixes(int $limit): array
    {
        $sql = "
            SELECT Prefix
            FROM FileS3_RepairControl
            WHERE Estado IN ('pendiente', 'error')
            ORDER BY Prefix ASC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando lectura de pendientes: ' . $this->db->error);
        }

        $stmt->bind_param('i', $limit);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Error leyendo pendientes: ' . $stmt->error);
        }

        $res = $stmt->get_result();
        $data = [];

        while ($row = $res->fetch_assoc()) {
            $data[] = (string)$row['Prefix'];
        }

        $stmt->close();

        return $data;
    }

    private function processFolder(string $prefix): void
    {
        $prefix = $this->normalizePrefix($prefix);
        $this->markControl($prefix, 'procesando', 'Iniciando revisión');

        try {
            $dbRows = $this->getDbRowsByPrefix($prefix);
            $s3Map  = $this->listS3FilesMap($prefix);

            $this->out("Carpeta: {$prefix}");
            $this->out("BD: " . count($dbRows) . " | S3: " . count($s3Map));

            $grouped = $this->groupDbByVisibleName($dbRows);

            $totalActions = 0;

            if (!$this->dryRun) {
                $this->db->begin_transaction();
            }

            foreach ($grouped as $visibleName => $rows) {
                $actions = $this->resolveGroup($prefix, $visibleName, $rows, $s3Map);

                foreach ($actions as $action) {
                    $this->applyAction($prefix, $action);
                    $totalActions++;
                }
            }

            if (!$this->dryRun) {
                $this->db->commit();
            }

            $this->markControl(
                $prefix,
                'revisado',
                'Revisión completada',
                count($s3Map),
                count($dbRows),
                $totalActions
            );

            $this->out("Acciones: {$totalActions}");
        } catch (Throwable $e) {
            if (!$this->dryRun) {
                $this->db->rollback();
            }

            $this->markControl($prefix, 'error', $e->getMessage());
            $this->out("ERROR: " . $e->getMessage());
        }
    }

    private function resolveGroup(string $prefix, string $visibleName, array $rows, array $s3Map): array
    {
        $actions = [];

        usort($rows, function (array $a, array $b): int {
            $cmpFecha = strcmp((string)$a['Fecha'], (string)$b['Fecha']);
            if ($cmpFecha !== 0) {
                return $cmpFecha;
            }
            return (int)$a['id_'] <=> (int)$b['id_'];
        });

        $validRows = [];
        $invalidRows = [];

        foreach ($rows as $row) {
            $currentEnc = trim((string)$row['Encriptado']);
            $expectedKey = $this->buildStoredKeyFromRow($row);
            $hasFullPath = $this->encriptadoHasFullPath($row);

            if ($expectedKey !== '' && isset($s3Map[$expectedKey])) {
                $validRows[] = [
                    'row' => $row,
                    's3key' => $expectedKey,
                    'has_fullpath' => $hasFullPath
                ];
            } else {
                $invalidRows[] = [
                    'row' => $row,
                    's3key' => $expectedKey,
                    'has_fullpath' => $hasFullPath
                ];
            }
        }

        $uniqueValidKeys = [];
        foreach ($validRows as $item) {
            $uniqueValidKeys[$item['s3key']][] = $item;
        }

        $realObjectsCount = count($uniqueValidKeys);

        /*
        |--------------------------------------------------------------------------
        | Caso 1: no hay ningún objeto real válido en S3 para este Nombre
        |--------------------------------------------------------------------------
        */
        if ($realObjectsCount === 0) {
            foreach ($rows as $row) {
                $actions[] = [
                    'type' => 'missing_in_s3',
                    'file_id' => (int)$row['id_'],
                    'nombre' => $visibleName,
                    'old_encrypted' => (string)$row['Encriptado'],
                    'detail' => 'No existe objeto real en S3 para este grupo'
                ];
            }
            return $actions;
        }

        /*
        |--------------------------------------------------------------------------
        | Normalizar válidos: si Encriptado no trae ruta, guardar la key completa
        |--------------------------------------------------------------------------
        */
        foreach ($uniqueValidKeys as $s3Key => $items) {
            usort($items, function (array $a, array $b): int {
                $rowA = $a['row'];
                $rowB = $b['row'];

                $cmpFecha = strcmp((string)$rowA['Fecha'], (string)$rowB['Fecha']);
                if ($cmpFecha !== 0) {
                    return $cmpFecha;
                }
                return (int)$rowA['id_'] <=> (int)$rowB['id_'];
            });

            $keeper = array_shift($items);
            $keeperRow = $keeper['row'];

            if (!$keeper['has_fullpath']) {
                $actions[] = [
                    'type' => 'update_encriptado_fullpath',
                    'file_id' => (int)$keeperRow['id_'],
                    'nombre' => $visibleName,
                    'old_encrypted' => (string)$keeperRow['Encriptado'],
                    'new_encrypted' => $s3Key,
                    'key' => $s3Key,
                    'detail' => 'Se guarda la ruta completa en Encriptado'
                ];
            } else {
                $actions[] = [
                    'type' => 'keep',
                    'file_id' => (int)$keeperRow['id_'],
                    'nombre' => $visibleName,
                    'key' => $s3Key,
                    'detail' => 'Registro válido'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Duplicados BD de la misma key real S3: eliminar sobrantes
            |--------------------------------------------------------------------------
            */
            foreach ($items as $dup) {
                $dupRow = $dup['row'];

                $actions[] = [
                    'type' => 'delete_duplicate_db',
                    'file_id' => (int)$dupRow['id_'],
                    'nombre' => $visibleName,
                    'old_encrypted' => (string)$dupRow['Encriptado'],
                    'key' => $s3Key,
                    'detail' => 'Duplicado BD de la misma key real en S3'
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Si ya tenemos objeto real para este Nombre y sobran inválidos, eliminarlos
        |--------------------------------------------------------------------------
        | Esto cubre justo tu regla:
        | - si ya existe uno correcto en esa ruta
        | - y este no resuelve a objeto real
        | - eliminar el registro sobrante de BD
        |--------------------------------------------------------------------------
        */
        foreach ($invalidRows as $invalid) {
            $row = $invalid['row'];

            $actions[] = [
                'type' => 'delete_duplicate_db',
                'file_id' => (int)$row['id_'],
                'nombre' => $visibleName,
                'old_encrypted' => (string)$row['Encriptado'],
                'key' => $invalid['s3key'],
                'detail' => 'Sobra en BD: ya existe al menos un objeto real válido para este Nombre'
            ];
        }

        return $actions;
    }

    private function applyAction(string $prefix, array $action): void
    {
        $this->logAction($prefix, $action);

        switch ($action['type']) {
            case 'keep':
                $this->out("[KEEP] ID {$action['file_id']} | {$action['nombre']}");
                return;

            case 'missing_in_s3':
                $this->out("[MISSING] ID {$action['file_id']} | {$action['nombre']}");
                return;

            case 'update_encriptado_fullpath':
                $this->out("[UPDATE] ID {$action['file_id']} | {$action['old_encrypted']} => {$action['new_encrypted']}");

                if (!$this->dryRun) {
                    $sql = "UPDATE FileS3 SET Encriptado = ?, Found = 1 WHERE id_ = ?";
                    $stmt = $this->db->prepare($sql);

                    if (!$stmt) {
                        throw new RuntimeException('Error preparando UPDATE: ' . $this->db->error);
                    }

                    $stmt->bind_param('si', $action['new_encrypted'], $action['file_id']);

                    if (!$stmt->execute()) {
                        $err = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Error actualizando id_=' . $action['file_id'] . ': ' . $err);
                    }

                    $stmt->close();
                }
                return;

            case 'delete_duplicate_db':
                $this->out("[DELETE] ID {$action['file_id']} | {$action['nombre']}");

                if (!$this->dryRun) {
                    $sql = "DELETE FROM FileS3 WHERE id_ = ?";
                    $stmt = $this->db->prepare($sql);

                    if (!$stmt) {
                        throw new RuntimeException('Error preparando DELETE: ' . $this->db->error);
                    }

                    $stmt->bind_param('i', $action['file_id']);

                    if (!$stmt->execute()) {
                        $err = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Error eliminando id_=' . $action['file_id'] . ': ' . $err);
                    }

                    $stmt->close();
                }
                return;

            default:
                $this->out("[WARN] Acción desconocida");
                return;
        }
    }

    private function logAction(string $prefix, array $action): void
    {
        $map = [
            'keep' => 'keep',
            'update_encriptado_fullpath' => 'update_encriptado_fullpath',
            'delete_duplicate_db' => 'delete_duplicate_db',
            'missing_in_s3' => 'missing_in_s3',
            'warning' => 'warning',
        ];

        $accion = $map[$action['type']] ?? 'warning';

        $sql = "
            INSERT INTO FileS3_RepairLog
            (Prefix, FileS3_id, Accion, Nombre, EncriptadoAntes, EncriptadoDespues, S3Key, Detalle)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando log: ' . $this->db->error);
        }

        $fileId = $action['file_id'] ?? null;
        $nombre = $action['nombre'] ?? null;
        $antes = $action['old_encrypted'] ?? null;
        $despues = $action['new_encrypted'] ?? null;
        $key = $action['key'] ?? null;
        $detail = $action['detail'] ?? null;

        $stmt->bind_param('sissssss', $prefix, $fileId, $accion, $nombre, $antes, $despues, $key, $detail);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Error insertando log: ' . $err);
        }

        $stmt->close();
    }

    private function markControl(
        string $prefix,
        string $estado,
        string $mensaje,
        int $totalS3 = 0,
        int $totalBD = 0,
        int $totalAcciones = 0
    ): void {
        $sql = "
            INSERT INTO FileS3_RepairControl
            (Prefix, Estado, UltimoMensaje, TotalS3, TotalBD, TotalAcciones, LastProcessedAt)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                Estado = VALUES(Estado),
                UltimoMensaje = VALUES(UltimoMensaje),
                TotalS3 = VALUES(TotalS3),
                TotalBD = VALUES(TotalBD),
                TotalAcciones = VALUES(TotalAcciones),
                LastProcessedAt = VALUES(LastProcessedAt)
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando control: ' . $this->db->error);
        }

        $stmt->bind_param('sssiii', $prefix, $estado, $mensaje, $totalS3, $totalBD, $totalAcciones);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Error guardando control: ' . $err);
        }

        $stmt->close();
    }

    private function getDbRowsByPrefix(string $prefix): array
    {
        $sql = "
            SELECT id_, user_id_, Ruta, Nombre, Encriptado, Fecha, Found
            FROM FileS3
            WHERE Ruta = ?
            ORDER BY Nombre ASC, Fecha ASC, id_ ASC
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error preparando SELECT FileS3: ' . $this->db->error);
        }

        $stmt->bind_param('s', $prefix);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Error leyendo FileS3: ' . $err);
        }

        $res = $stmt->get_result();
        $rows = [];

        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    }

    private function groupDbByVisibleName(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $name = trim((string)$row['Nombre']);
            if ($name === '') {
                $name = '__SIN_NOMBRE__';
            }
            $grouped[$name][] = $row;
        }

        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

        return $grouped;
    }

    private function listS3FilesMap(string $prefix): array
    {
        $prefix = $this->normalizePrefix($prefix);

        $map = [];
        $token = null;

        do {
            $params = [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ];

            if ($token) {
                $params['ContinuationToken'] = $token;
            }

            $result = $this->s3->listObjectsV2($params);

            foreach ($result['Contents'] ?? [] as $obj) {
                $key = $this->normalizeFileKey((string)$obj['Key']);

                if ($key === $prefix || substr($key, -1) === '/') {
                    continue;
                }

                $map[$key] = [
                    'Key' => $key,
                    'Size' => (int)($obj['Size'] ?? 0),
                ];
            }

            $token = !empty($result['IsTruncated'])
                ? ($result['NextContinuationToken'] ?? null)
                : null;

        } while ($token);

        return $map;
    }

    private function buildStoredKeyFromRow(array $row): string
    {
        $ruta = $this->normalizePrefix((string)($row['Ruta'] ?? ''));
        $enc  = trim((string)($row['Encriptado'] ?? ''));

        if ($enc === '') {
            return '';
        }

        $enc = $this->normalizeFileKey($enc);

        if (strpos($enc, $ruta) === 0) {
            return $enc;
        }

        return $this->normalizeFileKey($ruta . $enc);
    }

    private function encriptadoHasFullPath(array $row): bool
    {
        $ruta = $this->normalizePrefix((string)($row['Ruta'] ?? ''));
        $enc  = $this->normalizeFileKey((string)($row['Encriptado'] ?? ''));

        if ($enc === '') {
            return false;
        }

        return strpos($enc, $ruta) === 0;
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        $prefix = str_replace('\\', '/', $prefix);
        $prefix = preg_replace('~/+~', '/', $prefix);
        $prefix = ltrim($prefix, '/');

        if ($prefix === '') {
            $prefix = 'Data/';
        }

        if (substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        return $prefix;
    }

    private function normalizeFileKey(string $key): string
    {
        $key = trim($key);
        $key = str_replace('\\', '/', $key);
        $key = preg_replace('~/+~', '/', $key);
        return ltrim($key, '/');
    }

    private function out(string $text): void
    {
        echo $text . PHP_EOL;
    }
}

$repair = new FileS3RepairByFolderV2(
    $db_connection,
    Config::getS3(),
    Config::BUCKET
);

$repair->setDryRun(true);      // primero en prueba
$repair->setFolderLimit(1000);  // 100 carpetas por corrida
$repair->run();