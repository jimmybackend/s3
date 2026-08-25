<?php
declare(strict_types=1);

/**
 * ============================================================
 * RDS / AURORA STOP ONLY
 * ============================================================
 *
 * Este archivo sirve únicamente para apagar temporalmente la base de datos
 * indicada en DATABASE_IDS_TO_STOP.
 *
 * No controla EC2.
 * No enciende bases de datos.
 * No revisa horario.
 * No modifica otras instancias.
 *
 * Puede ejecutarse manualmente desde navegador o mediante cron de HostGator.
 */

require __DIR__ . '../vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\Rds\RdsClient;
use Aws\Exception\AwsException;

/**
 * ============================================================
 * CONFIGURACIÓN GENERAL
 * ============================================================
 */

$region = defined('Config::REGION') ? Config::REGION : 'us-east-1';

$tz = new DateTimeZone('America/Mexico_City');

/**
 * Log propio de este archivo.
 */
$LOG_FILE = __DIR__ . '/rds-stop-only.log';

/**
 * Bases de datos que este archivo debe apagar.
 *
 * Para RDS PostgreSQL normal:
 * - usar DBInstanceIdentifier.
 *
 * Para Aurora:
 * - usar DBClusterIdentifier.
 *
 * El script detecta automáticamente si el identificador corresponde
 * a una DB Instance o a un DB Cluster.
 */
const DATABASE_IDS_TO_STOP = [
    'esforzados-hub',
];

/**
 * Mostrar salida en navegador/cron.
 *
 * true:
 * - imprime mensajes en pantalla.
 *
 * false:
 * - solo escribe en log.
 */
const PRINT_OUTPUT = true;

/**
 * ============================================================
 * LOG Y SALIDA
 * ============================================================
 */

function logLine(string $msg): void
{
    global $LOG_FILE, $tz;

    $now = new DateTimeImmutable('now', $tz);

    file_put_contents(
        $LOG_FILE,
        '[' . $now->format('Y-m-d H:i:s T') . "] $msg\n",
        FILE_APPEND | LOCK_EX
    );
}

function outLine(string $msg): void
{
    if (PRINT_OUTPUT) {
        echo $msg . PHP_EOL;
    }

    logLine($msg);
}

/**
 * ============================================================
 * FUNCIONES RDS / AURORA
 * ============================================================
 */

function dbInstanceStatus(array $db): string
{
    return (string)($db['DBInstanceStatus'] ?? 'unknown');
}

function dbClusterStatus(array $cluster): string
{
    return (string)($cluster['Status'] ?? 'unknown');
}

/**
 * Solo se intenta apagar si está disponible.
 *
 * No se detiene si está:
 * - stopped
 * - stopping
 * - starting
 * - modifying
 * - backing-up
 * - maintenance
 *
 * Esto evita pelear contra operaciones internas de AWS.
 */
function isDatabaseStoppable(string $status): bool
{
    return $status === 'available';
}

function listAllDbInstances(RdsClient $rds): array
{
    $out = [];
    $params = [];

    do {
        $res = $rds->describeDBInstances($params);

        foreach ($res['DBInstances'] ?? [] as $db) {
            if (!empty($db['DBInstanceIdentifier'])) {
                $out[$db['DBInstanceIdentifier']] = $db;
            }
        }

        if (!empty($res['Marker'])) {
            $params = ['Marker' => $res['Marker']];
        } else {
            $params = [];
        }
    } while (!empty($params['Marker']));

    return $out;
}

function listAllDbClusters(RdsClient $rds): array
{
    $out = [];
    $params = [];

    do {
        $res = $rds->describeDBClusters($params);

        foreach ($res['DBClusters'] ?? [] as $cluster) {
            if (!empty($cluster['DBClusterIdentifier'])) {
                $out[$cluster['DBClusterIdentifier']] = $cluster;
            }
        }

        if (!empty($res['Marker'])) {
            $params = ['Marker' => $res['Marker']];
        } else {
            $params = [];
        }
    } while (!empty($params['Marker']));

    return $out;
}

/**
 * Busca primero como DB Instance normal.
 * Si no aparece, busca como Aurora DB Cluster.
 */
function findDatabaseTarget(string $id, array $dbInstances, array $dbClusters): ?array
{
    if (isset($dbInstances[$id])) {
        return [
            'type' => 'instance',
            'data' => $dbInstances[$id],
        ];
    }

    if (isset($dbClusters[$id])) {
        return [
            'type' => 'cluster',
            'data' => $dbClusters[$id],
        ];
    }

    return null;
}

function databaseTargetStatus(array $target): string
{
    if ($target['type'] === 'instance') {
        return dbInstanceStatus($target['data']);
    }

    if ($target['type'] === 'cluster') {
        return dbClusterStatus($target['data']);
    }

    return 'unknown';
}

function stopDatabaseTarget(RdsClient $rds, string $id, string $type): void
{
    if ($type === 'instance') {
        $rds->stopDBInstance([
            'DBInstanceIdentifier' => $id,
        ]);
        return;
    }

    if ($type === 'cluster') {
        $rds->stopDBCluster([
            'DBClusterIdentifier' => $id,
        ]);
        return;
    }

    throw new RuntimeException("Tipo de base de datos no soportado para STOP: {$type}");
}

/**
 * ============================================================
 * PROCESO PRINCIPAL
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    $now = new DateTimeImmutable('now', $tz);

    outLine('RDS STOP ONLY iniciado a las ' . $now->format('Y-m-d H:i:s T') . '.');

    $rds = new RdsClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => [
            'key'    => Config::ACCESS_KEY,
            'secret' => Config::SECRET_KEY,
        ],
    ]);

    $dbInstances = listAllDbInstances($rds);
    $dbClusters  = listAllDbClusters($rds);

    foreach (DATABASE_IDS_TO_STOP as $dbId) {
        $target = findDatabaseTarget($dbId, $dbInstances, $dbClusters);

        if ($target === null) {
            outLine('ERROR: la base de datos ' . $dbId . ' no fue encontrada como RDS Instance ni como Aurora Cluster.');
            continue;
        }

        $type   = $target['type'];
        $status = databaseTargetStatus($target);

        outLine(
            'DB ' . $dbId .
            " tipo '{$type}' detectada en estado '{$status}'."
        );

        if (isDatabaseStoppable($status)) {
            outLine(
                'DB ' . $dbId .
                " tipo '{$type}' está disponible. Intentando detener temporalmente."
            );

            stopDatabaseTarget($rds, $dbId, $type);

            outLine(
                'DB ' . $dbId .
                " tipo '{$type}' comando STOP enviado correctamente."
            );

            continue;
        }

        outLine(
            'DB ' . $dbId .
            " tipo '{$type}' no se apagó porque su estado actual es '{$status}'."
        );
    }

    outLine('RDS STOP ONLY finalizado.');

} catch (AwsException $e) {
    logLine(
        'AWS ERROR: ' .
        ($e->getAwsErrorCode() ?: 'AWS') .
        ' - ' .
        ($e->getAwsErrorMessage() ?: $e->getMessage())
    );

    if (PRINT_OUTPUT) {
        echo 'AWS ERROR: ' .
            ($e->getAwsErrorCode() ?: 'AWS') .
            ' - ' .
            ($e->getAwsErrorMessage() ?: $e->getMessage()) .
            PHP_EOL;
    }
} catch (Throwable $t) {
    logLine('FATAL ERROR: ' . $t->getMessage());

    if (PRINT_OUTPUT) {
        echo 'FATAL ERROR: ' . $t->getMessage() . PHP_EOL;
    }
}
