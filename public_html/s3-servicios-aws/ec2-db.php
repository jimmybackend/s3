<?php
declare(strict_types=1);

/**
 * Carga del autoload de Composer y del bootstrap de tu aplicación.
 * Aquí se asume que Config::ACCESS_KEY, Config::SECRET_KEY y Config::REGION
 * ya existen en tu proyecto.
 */
require_once __DIR__ . '/app_bootstrap.php';

use Aws\Ec2\Ec2Client;
use Aws\Rds\RdsClient;
use Aws\Exception\AwsException;

/**
 * ============================================================
 * CONFIGURACIÓN GENERAL
 * ============================================================
 */

/**
 * Región de AWS.
 * Si Config::REGION existe y tiene valor, se usa esa.
 * Si no existe, se usa us-east-1 por defecto.
 */
$region = defined('Config::REGION') ? Config::REGION : 'us-east-1';

/**
 * Zona horaria usada para evaluar horarios y para escribir el log.
 */
$tz = new DateTimeZone('America/Mexico_City');

/**
 * Ruta del archivo de log.
 */
$LOG_FILE = __DIR__ . '/ec2-db.log';

/**
 * ============================================================
 * FUNCIÓN DE LOG
 * ============================================================
 */

/**
 * Escribe una línea en el log con fecha y hora reales del momento exacto.
 *
 * Importante:
 * - Ya no usa una hora fija tomada al inicio del script.
 * - Cada línea tendrá su timestamp real.
 * - Solo debe llamarse cuando ocurra una acción o un error.
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

/**
 * ============================================================
 * INSTANCIAS EC2 PRIORITARIAS
 * ============================================================
 */

/**
 * Instancias principales EC2 que siempre deben estar encendidas.
 *
 * Si cualquiera de estas instancias se detecta apagada,
 * este cron intentará iniciarla automáticamente.
 *
 * Importante:
 * - i-091f5ddb0e2b42656 = primaria actual / web
 */
const MUST_RUN_INSTANCE_IDS = [
    'i-091f5ddb0e2b42656',
    // 'i-0978e1ba7e04a9d69',
];

/**
 * Lista blanca de EC2 prioritarias.
 *
 * Todo lo que esté aquí:
 * - nunca se apaga automáticamente
 * - se considera prioridad / principal
 * - queda protegido contra el apagado fuera de horario
 */
const PRIORITY_INSTANCE_IDS = MUST_RUN_INSTANCE_IDS;

/**
 * ============================================================
 * BASES DE DATOS RDS / AURORA
 * ============================================================
 *
 * OJO:
 * - Si es RDS PostgreSQL normal, el identificador normalmente es el
 *   DBInstanceIdentifier, por ejemplo: esforzados-hub.
 * - Si es Aurora, el identificador que se debe usar para apagar/encender
 *   es el DBClusterIdentifier, no una instancia secundaria suelta.
 *
 * Este script intenta detectar automáticamente si el identificador existe como:
 * - DB Instance normal: describeDBInstances()
 * - DB Cluster Aurora: describeDBClusters()
 */

/**
 * Bases de datos que SIEMPRE deben estar encendidas.
 *
 * Úsalo si la API principal depende de esta base de datos todo el día.
 *
 * Ejemplo:
 * const MUST_RUN_DATABASE_IDS = [
 *     'esforzados-hub',
 * ];
 */
const MUST_RUN_DATABASE_IDS = [
    // 'esforzados-hub',
];

/**
 * Bases de datos controladas por horario.
 *
 * Dentro del horario permitido:
 * - si están detenidas, se intentan iniciar.
 *
 * Fuera del horario permitido:
 * - si están disponibles, se intentan detener temporalmente.
 *
 * Para tu caso, si quieres ahorrar costo y apagarla fuera de horario,
 * deja aquí esforzados-hub.
 */
const SCHEDULED_DATABASE_IDS = [
    'esforzados-hub',
];

/**
 * ============================================================
 * HORARIOS DE OPERACIÓN
 * ============================================================
 */

/**
 * Horario permitido para que las secundarias EC2 y las bases programadas
 * puedan permanecer encendidas:
 * desde las 08:00:00 hasta las 15:59:59.
 */
const ALLOW_ON_FROM_HOUR = 8;   // 08:00
const STOP_AT_HOUR       = 16;  // desde 16:00 deben apagarse
const FORCE_AT_HOUR      = 17;  // desde 17:00 se permite apagado forzado para EC2

/**
 * ============================================================
 * FUNCIONES AUXILIARES EC2
 * ============================================================
 */

/**
 * Devuelve el nombre del estado actual de una instancia EC2.
 */
function stateName(array $inst): string
{
    return (string)($inst['State']['Name'] ?? 'unknown');
}

/**
 * Indica si una instancia EC2 está encendida o en proceso de encendido.
 */
function isRunningLike(string $st): bool
{
    return in_array($st, ['running', 'pending'], true);
}

/**
 * Indica si una instancia EC2 ya está apagada o en proceso de apagado.
 */
function isStoppedLike(string $st): bool
{
    return in_array($st, ['stopped', 'stopping', 'shutting-down', 'terminated'], true);
}

/**
 * Indica si una instancia EC2 pertenece al grupo prioritario.
 */
function isPriority(string $id): bool
{
    return in_array($id, PRIORITY_INSTANCE_IDS, true);
}

/**
 * Regla de negocio:
 * Las secundarias SOLO pueden estar encendidas entre 08:00 y 15:59.
 */
function secondaryMustBeOffNow(DateTimeImmutable $now): bool
{
    $h = (int)$now->format('H');
    $m = (int)$now->format('i');

    // Antes de las 08:00
    if ($h < ALLOW_ON_FROM_HOUR) {
        return true;
    }

    // Desde las 16:00 en adelante
    if ($h > STOP_AT_HOUR || ($h === STOP_AT_HOUR && $m >= 0)) {
        return true;
    }

    // Entre 08:00 y 15:59
    return false;
}

/**
 * Indica si estamos dentro del horario permitido.
 */
function mayBeOnNow(DateTimeImmutable $now): bool
{
    return !secondaryMustBeOffNow($now);
}

/**
 * Indica si ya estamos en horario de apagado forzado para EC2.
 */
function shouldForceNow(DateTimeImmutable $now): bool
{
    $h = (int)$now->format('H');
    $m = (int)$now->format('i');

    return ($h > FORCE_AT_HOUR) || ($h === FORCE_AT_HOUR && $m >= 0);
}

/**
 * ============================================================
 * FUNCIONES AUXILIARES RDS / AURORA
 * ============================================================
 */

/**
 * Estado de una DB Instance RDS normal.
 *
 * Ejemplos:
 * - available
 * - stopped
 * - starting
 * - stopping
 * - backing-up
 * - modifying
 */
function dbInstanceStatus(array $db): string
{
    return (string)($db['DBInstanceStatus'] ?? 'unknown');
}

/**
 * Estado de un DB Cluster Aurora.
 *
 * Ejemplos:
 * - available
 * - stopped
 * - starting
 * - stopping
 */
function dbClusterStatus(array $cluster): string
{
    return (string)($cluster['Status'] ?? 'unknown');
}

/**
 * RDS/Aurora solo debe iniciar si está detenido.
 */
function isDatabaseStartable(string $status): bool
{
    return $status === 'stopped';
}

/**
 * RDS/Aurora solo debe detenerse automáticamente si está disponible.
 *
 * No detenemos si está starting, stopping, modifying, backing-up, etc.,
 * para evitar pelear contra operaciones internas de AWS.
 */
function isDatabaseStoppable(string $status): bool
{
    return $status === 'available';
}

/**
 * Obtiene todas las instancias RDS visibles para estas credenciales.
 *
 * Devuelve:
 * [
 *   'db-identifier' => [...datos completos...],
 * ]
 */
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

/**
 * Obtiene todos los clusters Aurora visibles para estas credenciales.
 *
 * Devuelve:
 * [
 *   'cluster-identifier' => [...datos completos...],
 * ]
 */
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
 * Busca un identificador de base de datos como RDS Instance o como Aurora Cluster.
 *
 * Retorna:
 * [
 *   'type' => 'instance'|'cluster',
 *   'data' => [...],
 * ]
 *
 * Si no existe, retorna null.
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

/**
 * Enciende una DB normal o un cluster Aurora según corresponda.
 */
function startDatabaseTarget(RdsClient $rds, string $id, string $type): void
{
    if ($type === 'instance') {
        $rds->startDBInstance([
            'DBInstanceIdentifier' => $id,
        ]);
        return;
    }

    if ($type === 'cluster') {
        $rds->startDBCluster([
            'DBClusterIdentifier' => $id,
        ]);
        return;
    }

    throw new RuntimeException("Tipo de base de datos no soportado para START: {$type}");
}

/**
 * Detiene temporalmente una DB normal o un cluster Aurora según corresponda.
 */
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
 * Devuelve el estado según tipo.
 */
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

/**
 * ============================================================
 * CLIENTES AWS
 * ============================================================
 */

$ec2 = new Ec2Client([
    'region'      => $region,
    'version'     => 'latest',
    'credentials' => [
        'key'    => Config::ACCESS_KEY,
        'secret' => Config::SECRET_KEY,
    ],
]);

$rds = new RdsClient([
    'region'      => $region,
    'version'     => 'latest',
    'credentials' => [
        'key'    => Config::ACCESS_KEY,
        'secret' => Config::SECRET_KEY,
    ],
]);

/**
 * Obtiene todas las instancias EC2 visibles para estas credenciales
 * en la región configurada.
 */
function listAllInstances(Ec2Client $ec2): array
{
    $out = [];
    $params = [];

    do {
        $res = $ec2->describeInstances($params);

        foreach ($res['Reservations'] ?? [] as $reservation) {
            foreach ($reservation['Instances'] ?? [] as $instance) {
                if (!empty($instance['InstanceId'])) {
                    $out[$instance['InstanceId']] = $instance;
                }
            }
        }

        $params['NextToken'] = $res['NextToken'] ?? null;
    } while (!empty($params['NextToken']));

    return $out;
}

/**
 * ============================================================
 * PROCESO PRINCIPAL DEL CRON
 * ============================================================
 */
try {
    /**
     * Hora actual usada para evaluar reglas de negocio.
     */
    $now = new DateTimeImmutable('now', $tz);

    /**
     * ------------------------------------------------------------
     * 1) EC2: validar primarias y apagar secundarias fuera de horario
     * ------------------------------------------------------------
     */
    $instances = listAllInstances($ec2);

    foreach (MUST_RUN_INSTANCE_IDS as $primaryInstanceId) {
        if (isset($instances[$primaryInstanceId])) {
            $primaryState = stateName($instances[$primaryInstanceId]);

            if (!isRunningLike($primaryState)) {
                logLine(
                    'EC2 PRIMARIA ' . $primaryInstanceId .
                    " detectada en estado '{$primaryState}'. Intentando encender."
                );

                $ec2->startInstances([
                    'InstanceIds' => [$primaryInstanceId],
                ]);

                logLine(
                    'EC2 PRIMARIA ' . $primaryInstanceId .
                    ' comando START enviado correctamente.'
                );
            }
        } else {
            logLine('ERROR: la instancia EC2 principal ' . $primaryInstanceId . ' no fue encontrada.');
        }
    }

    $mustOff = secondaryMustBeOffNow($now);
    $force   = shouldForceNow($now);

    if ($mustOff) {
        foreach ($instances as $id => $inst) {
            if (isPriority($id)) {
                continue;
            }

            $state = stateName($inst);

            if (isRunningLike($state)) {
                logLine(
                    'EC2 SECUNDARIA ' . $id .
                    " detectada en estado '{$state}' fuera de horario (" . $now->format('H:i') . '). ' .
                    'Intentando apagar' . ($force ? ' con FORCE=true' : ' con FORCE=false') . '.'
                );

                $ec2->stopInstances([
                    'InstanceIds' => [$id],
                    'Force'       => $force,
                ]);

                logLine(
                    'EC2 SECUNDARIA ' . $id .
                    ' comando STOP enviado correctamente' . ($force ? ' con FORCE=true.' : '.')
                );
            }
        }
    }

    /**
     * ------------------------------------------------------------
     * 2) RDS / AURORA: bases que siempre deben estar encendidas
     * ------------------------------------------------------------
     */
    $dbInstances = listAllDbInstances($rds);
    $dbClusters  = listAllDbClusters($rds);

    foreach (MUST_RUN_DATABASE_IDS as $dbId) {
        $target = findDatabaseTarget($dbId, $dbInstances, $dbClusters);

        if ($target === null) {
            logLine('ERROR: la base de datos prioritaria ' . $dbId . ' no fue encontrada como RDS Instance ni como Aurora Cluster.');
            continue;
        }

        $type   = $target['type'];
        $status = databaseTargetStatus($target);

        if (isDatabaseStartable($status)) {
            logLine(
                'DB PRIORITARIA ' . $dbId .
                " tipo '{$type}' detectada en estado '{$status}'. Intentando encender."
            );

            startDatabaseTarget($rds, $dbId, $type);

            logLine(
                'DB PRIORITARIA ' . $dbId .
                " tipo '{$type}' comando START enviado correctamente."
            );
        }
    }

    /**
     * ------------------------------------------------------------
     * 3) RDS / AURORA: bases controladas por horario
     * ------------------------------------------------------------
     */
    $mayBeOn = mayBeOnNow($now);

    foreach (SCHEDULED_DATABASE_IDS as $dbId) {
        /**
         * Si además está marcada como prioritaria, no se controla por horario.
         */
        if (in_array($dbId, MUST_RUN_DATABASE_IDS, true)) {
            continue;
        }

        $target = findDatabaseTarget($dbId, $dbInstances, $dbClusters);

        if ($target === null) {
            logLine('ERROR: la base de datos programada ' . $dbId . ' no fue encontrada como RDS Instance ni como Aurora Cluster.');
            continue;
        }

        $type   = $target['type'];
        $status = databaseTargetStatus($target);

        if ($mayBeOn) {
            /**
             * Dentro de horario permitido:
             * si está detenida, se enciende.
             */
            if (isDatabaseStartable($status)) {
                logLine(
                    'DB PROGRAMADA ' . $dbId .
                    " tipo '{$type}' detectada en estado '{$status}' dentro de horario (" . $now->format('H:i') . '). Intentando encender.'
                );

                startDatabaseTarget($rds, $dbId, $type);

                logLine(
                    'DB PROGRAMADA ' . $dbId .
                    " tipo '{$type}' comando START enviado correctamente."
                );
            }
        } else {
            /**
             * Fuera de horario:
             * si está disponible, se detiene temporalmente.
             */
            if (isDatabaseStoppable($status)) {
                logLine(
                    'DB PROGRAMADA ' . $dbId .
                    " tipo '{$type}' detectada en estado '{$status}' fuera de horario (" . $now->format('H:i') . '). Intentando detener temporalmente.'
                );

                stopDatabaseTarget($rds, $dbId, $type);

                logLine(
                    'DB PROGRAMADA ' . $dbId .
                    " tipo '{$type}' comando STOP enviado correctamente."
                );
            }
        }
    }

} catch (AwsException $e) {
    logLine(
        'AWS ERROR: ' .
        ($e->getAwsErrorCode() ?: 'AWS') .
        ' - ' .
        ($e->getAwsErrorMessage() ?: $e->getMessage())
    );
} catch (Throwable $t) {
    logLine('FATAL ERROR: ' . $t->getMessage());
}
