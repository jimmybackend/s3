<?php
declare(strict_types=1);

/**
 * Carga del autoload de Composer y del bootstrap de tu aplicación.
 * Aquí se asume que Config::ACCESS_KEY, Config::SECRET_KEY y Config::REGION
 * ya existen en tu proyecto.
 */
require_once __DIR__ . '/app_bootstrap.php';

use Aws\Ec2\Ec2Client;
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
$LOG_FILE = __DIR__ . '/ec2-cron.log';

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
 * INSTANCIAS PRIORITARIAS
 * ============================================================
 */

/**
 * Instancias principales que siempre deben estar encendidas.
 *
 * Si cualquiera de estas instancias se detecta apagada,
 * este cron intentará iniciarla automáticamente.
 *
 * Importante:
 * - i-097146ee51c7f7026 = ✅ NUEVO servidor principal (mailit.click)
 * - i-0978e1ba7e04a9d69 = servidor-email (comentado, ajustar si se necesita)
 * - i-091f5ddb0e2b42656 = ❌ SERVIDOR ANTIGUO (NUNCA debe encenderse)
 */
const MUST_RUN_INSTANCE_IDS = [
    'i-097146ee51c7f7026', 
    // 'i-0978e1ba7e04a9d69', 
    // 'i-091f5ddb0e2b42656', 
];

/**
 * Lista blanca de instancias prioritarias.
 *
 * Todo lo que esté aquí:
 * - nunca se apaga automáticamente
 * - se considera prioridad / principal
 * - queda protegido contra el apagado fuera de horario
 *
 * Todo lo que NO esté aquí:
 * - se considera secundaria
 * - puede apagarse fuera del horario permitido
 */
const PRIORITY_INSTANCE_IDS = MUST_RUN_INSTANCE_IDS;

/**
 * ============================================================
 * HORARIOS DE OPERACIÓN
 * ============================================================
 */

/**
 * Horario permitido para que las secundarias puedan permanecer encendidas:
 * desde las 08:00:00 hasta las 15:59:59
 */
const ALLOW_ON_FROM_HOUR = 8;   // 08:00
const STOP_AT_HOUR       = 16;  // desde 16:00 deben apagarse
const FORCE_AT_HOUR      = 17;  // desde 17:00 se permite apagado forzado

/**
 * ============================================================
 * FUNCIONES AUXILIARES
 * ============================================================
 */

/**
 * Devuelve el nombre del estado actual de una instancia EC2.
 *
 * Ejemplos:
 * - running
 * - stopped
 * - pending
 * - stopping
 */
function stateName(array $inst): string
{
    return (string)($inst['State']['Name'] ?? 'unknown');
}

/**
 * Indica si una instancia está encendida o en proceso de encendido.
 *
 * Se considera "encendida funcionalmente" cuando está:
 * - running
 * - pending
 *
 * Esto sirve para saber si aún no hace falta enviar start.
 */
function isRunningLike(string $st): bool
{
    return in_array($st, ['running', 'pending'], true);
}

/**
 * Indica si una instancia ya está apagada o en proceso de apagado.
 *
 * Se considera "apagada funcionalmente" cuando está:
 * - stopped
 * - stopping
 * - shutting-down
 * - terminated
 *
 * Ojo:
 * En esta versión no se usa directamente para loguear estados,
 * pero queda útil y documentada para futuras mejoras.
 */
function isStoppedLike(string $st): bool
{
    return in_array($st, ['stopped', 'stopping', 'shutting-down', 'terminated'], true);
}

/**
 * Indica si una instancia pertenece al grupo prioritario.
 *
 * Si devuelve true:
 * - no debe apagarse como secundaria
 */
function isPriority(string $id): bool
{
    return in_array($id, PRIORITY_INSTANCE_IDS, true);
}

/**
 * Regla de negocio:
 * Las secundarias SOLO pueden estar encendidas entre 08:00 y 15:59.
 *
 * Devuelve true si, en este momento, las secundarias DEBEN estar apagadas.
 *
 * Casos en que devuelve true:
 * - antes de las 08:00
 * - desde las 16:00 en adelante
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
 * Indica si ya estamos en horario de apagado forzado.
 *
 * Devuelve true desde las 17:00 en adelante.
 * Antes de esa hora, el apagado se intenta sin Force.
 */
function shouldForceNow(DateTimeImmutable $now): bool
{
    $h = (int)$now->format('H');
    $m = (int)$now->format('i');

    return ($h > FORCE_AT_HOUR) || ($h === FORCE_AT_HOUR && $m >= 0);
}

/**
 * ============================================================
 * CLIENTE AWS EC2
 * ============================================================
 */

/**
 * Cliente de AWS EC2.
 *
 * Con este objeto se llaman:
 * - describeInstances()
 * - startInstances()
 * - stopInstances()
 */
$ec2 = new Ec2Client([
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
 *
 * Devuelve un array indexado por InstanceId:
 *
 * [
 *   'i-xxxx' => [...datos completos de la instancia...],
 *   'i-yyyy' => [...datos completos de la instancia...],
 * ]
 *
 * Usa paginación por si AWS devuelve resultados en varias páginas.
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
 *
 * Esta versión está pensada para generar log mínimo:
 *
 * SOLO escribe cuando:
 * - la primaria necesita start
 * - una secundaria necesita stop
 * - hay un error
 *
 * Si todo está bien, no escribe nada.
 */
try {
    /**
     * Hora actual usada para evaluar reglas de negocio.
     * Se toma una sola vez para que toda la ejecución use la misma referencia.
     */
    $now = new DateTimeImmutable('now', $tz);

    /**
     * Carga todas las instancias disponibles en AWS.
     */
    $instances = listAllInstances($ec2);

    /**
     * ------------------------------------------------------------
     * 1) VALIDAR E INICIAR LAS INSTANCIAS PRINCIPALES SI ES NECESARIO
     * ------------------------------------------------------------
     */
    foreach (MUST_RUN_INSTANCE_IDS as $primaryInstanceId) {
        if (isset($instances[$primaryInstanceId])) {
            $primaryState = stateName($instances[$primaryInstanceId]);

            /**
             * Si la principal NO está running ni pending,
             * se intenta encender y se registra en log.
             */
            if (!isRunningLike($primaryState)) {
                logLine(
                    'PRIMARIA ' . $primaryInstanceId .
                    " detectada en estado '{$primaryState}'. Intentando encender."
                );

                $ec2->startInstances([
                    'InstanceIds' => [$primaryInstanceId],
                ]);

                logLine(
                    'PRIMARIA ' . $primaryInstanceId .
                    ' comando START enviado correctamente.'
                );
            }
        } else {
            /**
             * Si una principal ni siquiera aparece en describeInstances,
             * eso sí debe quedar registrado como error.
             */
            logLine('ERROR: la instancia principal ' . $primaryInstanceId . ' no fue encontrada.');
        }
    }

    /**
     * ------------------------------------------------------------
     * 2) APAGAR SECUNDARIAS SOLO SI ESTÁN FUERA DE HORARIO
     * ------------------------------------------------------------
     */
    $mustOff = secondaryMustBeOffNow($now);
    $force   = shouldForceNow($now);

    /**
     * Solo se procesa apagado de secundarias fuera de horario.
     * Dentro del horario permitido no se escribe nada y no se hace nada.
     */
    if ($mustOff) {
        foreach ($instances as $id => $inst) {
            /**
             * Las instancias prioritarias se excluyen del apagado automático.
             */
            if (isPriority($id)) {
                continue;
            }

            $state = stateName($inst);

            /**
             * Solo actuar si la secundaria está encendida o arrancando.
             * Si ya está apagada o apagándose, no se escribe nada.
             */
            if (isRunningLike($state)) {
                logLine(
                    'SECUNDARIA ' . $id .
                    " detectada en estado '{$state}' fuera de horario (" . $now->format('H:i') . '). ' .
                    'Intentando apagar' . ($force ? ' con FORCE=true' : ' con FORCE=false') . '.'
                );

                $ec2->stopInstances([
                    'InstanceIds' => [$id],
                    'Force'       => $force,
                ]);

                logLine(
                    'SECUNDARIA ' . $id .
                    ' comando STOP enviado correctamente' . ($force ? ' con FORCE=true.' : '.')
                );
            }
        }
    }

} catch (AwsException $e) {
    /**
     * Error específico del SDK de AWS.
     * Se registra el código y el mensaje devuelto por AWS.
     */
    logLine(
        'AWS ERROR: ' .
        ($e->getAwsErrorCode() ?: 'AWS') .
        ' - ' .
        ($e->getAwsErrorMessage() ?: $e->getMessage())
    );
} catch (Throwable $t) {
    /**
     * Cualquier otro error fatal de PHP o del script.
     */
    logLine('FATAL ERROR: ' . $t->getMessage());
}