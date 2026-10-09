<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Aws\Ec2Gateway;
use ArcadeCloud\Drive\Office\OfficeActivityProbe;
use ArcadeCloud\Drive\Office\OfficeSessionReconciler;
use ArcadeCloud\Drive\System\Ec2InstanceIdentityService;
use ArcadeCloud\Drive\System\ComputeNodeAdmissionLock;
use mysqli;
use RuntimeException;
use Closure;

final class MediaWorkerNodeService
{
    private const MIN_VCPU = 2;
    // Una instancia de 8 GiB expone algo menos a Linux por memoria reservada.
    private const MIN_VISIBLE_MEMORY_BYTES = 7 * 1024 * 1024 * 1024;
    private const TEMP_SPACE_MULTIPLIER = 2.25;
    private const IDLE_WARNING_SECONDS = 30;

    private string $instanceId;
    private string $region;
    private int $idleGraceSeconds;
    private ?float $hourlyUsd;
    private ?Ec2Gateway $ec2 = null;
    private MediaWorkerNodeSessionRepository $sessions;
    private ActivityCostRecorder $activity;
    private ?string $lastBlockerSignature = null;
    /** @var Closure():array<string,string> */
    private Closure $localIdentity;

    /** @param null|Closure():array<string,string> $localIdentity */
    public function __construct(private mysqli $db, ?Closure $localIdentity = null)
    {
        $this->localIdentity = $localIdentity ?? static fn(): array => (new Ec2InstanceIdentityService())->current();
        $this->instanceId = trim((string)(getenv('ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID') ?: ''));
        $this->region = trim((string)(getenv('ARCADECLOUD_MEDIA_WORKER_REGION') ?: getenv('AWS_REGION') ?: ''));

        $role = strtolower(trim((string)(getenv('ARCADECLOUD_NODE_ROLE') ?: 'web')));

        // En el gateway pequeño Office/Drive no ejecuta el worker, pero sí debe
        // poder tocar y consultar la misma sesión de inactividad del FastDrive.
        // Si no hay target multimedia explícito, reutiliza únicamente el target
        // FastDrive administrado del gateway. Así office.esforzados.com y
        // fastdrive.esforzados.com escriben sobre la misma EC2 y la misma sesión.
        if (
            $this->instanceId === ''
            && !in_array($role, ['media-worker', 'combined'], true)
        ) {
            $fastDriveInstanceId = trim((string)(getenv('ARCADECLOUD_FASTDRIVE_INSTANCE_ID') ?: ''));
            if (preg_match('/^i-[0-9a-f]{8,17}$/i', $fastDriveInstanceId)) {
                $this->instanceId = $fastDriveInstanceId;
                if ($this->region === '') {
                    $this->region = trim((string)(getenv('ARCADECLOUD_FASTDRIVE_REGION') ?: ''));
                }
            }
        }

        // En un nodo grande instalado como media-worker/combined no obligamos a
        // duplicar su propio Instance ID en runtime-env.json. IMDSv2 permite que
        // la EC2 se identifique a sí misma de forma segura y mantenga operativo
        // el autoapagado aunque la variable administrada no exista.
        if (
            $this->instanceId === ''
            && in_array($role, ['media-worker', 'combined'], true)
        ) {
            $identity = ($this->localIdentity)();
            $this->instanceId = trim((string)($identity['instance_id'] ?? ''));
            if ($this->region === '') {
                $this->region = trim((string)($identity['region'] ?? ''));
            }
        }

        if ($this->region === '') {
            $this->region = 'us-east-1';
        }
        // El nodo grande permanece disponible 20 minutos desde la última
        // actividad real. Después se abre una ventana adicional de 30 s para
        // que la interfaz pueda avisar y cancelar el apagado.
        $this->idleGraceSeconds = max(
            1200,
            min(3600, (int)(getenv('ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS') ?: 1200))
        );

        $hourly = trim((string)(getenv('ARCADECLOUD_MEDIA_WORKER_HOURLY_USD') ?: ''));
        $this->hourlyUsd = $hourly !== '' && is_numeric($hourly) && (float)$hourly >= 0
            ? (float)$hourly
            : null;

        $this->sessions = new MediaWorkerNodeSessionRepository($db);
        $this->activity = ActivityCostRecorder::fromDatabase($db);
        if ($this->instanceId !== '') {
            $this->ec2 = new Ec2Gateway($this->region);
        }
    }

    public function status(int $sourceBytes = 0): array
    {
        // La máquina actual se prueba primero por capacidad real. El rol es
        // informativo y nunca decide por sí solo si puede procesar multimedia.
        $local = $this->localStatus($sourceBytes);
        if (($local['can_enqueue'] ?? false) === true) {
            return $local;
        }

        // Sólo si el nodo actual no puede procesar de forma segura se considera
        // la EC2 remota configurada como fallback.
        if ($this->instanceId === '' || $this->ec2 === null) {
            return $local;
        }

        return $this->remoteStatus($local);
    }

    public function prepareForWork(int $userId, bool $authorizedStart, int $sourceBytes = 0): array
    {
        $status = $this->status($sourceBytes);
        $state = (string)($status['state'] ?? 'unknown');

        if ($state === 'dependency_missing') {
            throw new RuntimeException(
                '[DEPENDENCY_MISSING] ' . (string)($status['message']
                    ?? 'El nodo multimedia no tiene FFmpeg/FFprobe disponibles.')
            );
        }
        if ($state === 'insufficient_capacity') {
            throw new RuntimeException(
                '[CAPACITY_INSUFFICIENT] ' . (string)($status['message']
                    ?? 'El nodo multimedia no cumple la capacidad mínima.')
            );
        }
        if ($state === 'worker_inactive') {
            throw new RuntimeException(
                '[MEDIA_WORKER_UNAVAILABLE] ' . (string)($status['message']
                    ?? 'La máquina tiene capacidad, pero el worker multimedia local no está activo.')
            );
        }
        if (($status['configured'] ?? false) !== true) {
            throw new RuntimeException(
                '[MEDIA_WORKER_UNAVAILABLE] ' . (string)($status['message']
                    ?? 'No hay un nodo multimedia disponible para esta instalación.')
            );
        }

        if (in_array($state, ['running','pending'], true)) {
            return $status;
        }

        if ($state === 'stopped') {
            if ($this->hourlyUsd === null) {
                throw new RuntimeException(
                    '[NODE_RATE_REQUIRED] Configura ARCADECLOUD_MEDIA_WORKER_HOURLY_USD '
                    . 'antes de permitir encendidos pagados bajo demanda.'
                );
            }

            if (!$authorizedStart) {
                throw new RuntimeException(
                    '[NODE_START_AUTH_REQUIRED] El nodo de alto rendimiento está apagado. '
                    . 'Autoriza su encendido para crear esta tarea.'
                );
            }

            $session = $this->sessions->create(
                $userId,
                $this->instanceId,
                $this->region,
                (string)($status['instance_type'] ?? ''),
                $this->hourlyUsd
            );

            try {
                $this->ec2?->start($this->instanceId);
            } catch (\Throwable $e) {
                $this->sessions->markFailed((string)$session['session_id'], $e->getMessage());
                error_log('[ArcadeCloud media-node] EC2 start failed: ' . $e->getMessage());
                throw new RuntimeException(
                    'No se pudo encender el nodo multimedia. El superusuario debe revisar los permisos ec2:StartInstances.'
                );
            }

            $status['state'] = 'pending';
            $status['authorization_required'] = false;
            $status['session_active'] = true;
            $status['started_on_demand'] = true;
            $status['message'] = 'Encendido de la EC2 multimedia autorizado y solicitado.';
            return $status;
        }

        if ($state === 'stopping') {
            throw new RuntimeException('El nodo multimedia se está apagando. Espera a que termine y vuelve a intentarlo.');
        }

        throw new RuntimeException('El nodo multimedia está en un estado no utilizable: ' . $state . '.');
    }

    /**
     * Admite el trabajo y lo hace visible en la cola bajo el mismo mutex que
     * protege la decisión de apagado. Así no existe una ventana entre
     * "nodo disponible" y "job persistido".
     *
     * @return array{node:array<string,mixed>,result:mixed}
     */
    public function admitWork(
        int $userId,
        bool $authorizedStart,
        int $sourceBytes,
        callable $admission
    ): array {
        return $this->withAdmissionLock(function () use ($userId, $authorizedStart, $sourceBytes, $admission): array {
            $node = $this->prepareForWork($userId, $authorizedStart, $sourceBytes);
            return ['node' => $node, 'result' => $admission()];
        });
    }

    public function markBusy(): void
    {
        if ($this->instanceId === '') return;
        $active = $this->sessions->activeForInstance($this->instanceId);
        if ($active !== null) {
            $this->sessions->markRunning((string)$active['session_id']);
        }
    }

    public function touchInteractiveActivity(int $userId): array
    {
        if ($this->instanceId === '' || $this->ec2 === null) {
            return [
                'configured' => false,
                'state' => 'unconfigured',
                'message' => 'No hay una EC2 de alto rendimiento configurada.',
            ];
        }

        $instance = $this->ec2->getInstance($this->instanceId);
        if (!is_array($instance)) {
            throw new RuntimeException('No se pudo consultar la EC2 de alto rendimiento.');
        }

        $state = Ec2Gateway::stateName($instance);
        if ($state !== 'running') {
            return $this->idleStatus();
        }

        $active = $this->sessions->activeForInstance($this->instanceId);

        // Si AWS está realmente running pero la DB conserva una sesión
        // "stopping" de la ejecución anterior, esa sesión ya no describe el
        // ciclo actual. Se finaliza y la actividad abre una sesión limpia.
        if ($active !== null && (string)($active['status'] ?? '') === 'stopping') {
            $launch = $instance['LaunchTime'] ?? null;
            $launchTs = $launch instanceof \DateTimeInterface ? $launch->getTimestamp()
                : (is_string($launch) ? strtotime($launch) : false);
            $stopTs = strtotime((string)($active['stop_requested_at'] ?? '') . ' UTC');
            // DescribeInstances can remain running briefly after StopInstances.
            // Only a demonstrably newer boot may replace the stopping session.
            if ($launchTs === false || $stopTs === false || $launchTs <= $stopTs) {
                return $this->idleStatus();
            }
            $this->sessions->markStopped((string)$active['session_id']);
            $active = null;
        }

        if ($active === null) {
            $active = $this->sessions->create(
                $userId,
                $this->instanceId,
                $this->region,
                (string)($instance['InstanceType'] ?? ''),
                $this->hourlyUsd
            );
        }

        $this->sessions->clearIdle((string)$active['session_id']);
        return $this->idleStatus();
    }

    public function idleStatus(): array
    {
        if ($this->instanceId === '' || $this->ec2 === null) {
            return [
                'configured' => false,
                'state' => 'unconfigured',
                'idle_grace_seconds' => $this->idleGraceSeconds,
                'warning_seconds' => self::IDLE_WARNING_SECONDS,
                'warning' => false,
                'shutdown_in_seconds' => null,
            ];
        }

        $instance = $this->ec2->getInstance($this->instanceId);
        $state = is_array($instance) ? Ec2Gateway::stateName($instance) : 'unknown';
        $active = $this->sessions->activeForInstance($this->instanceId);

        if ($active !== null && $state === 'stopped') {
            $this->finalizeStoppedSession($active, 'stopped_detected');
            $active = null;
        }

        $idleSince = $active !== null ? trim((string)($active['idle_since'] ?? '')) : '';
        $idleElapsed = 0;
        if ($idleSince !== '') {
            $idleTs = strtotime($idleSince . ' UTC');
            if ($idleTs !== false) {
                $idleElapsed = max(0, time() - $idleTs);
            }
        }

        $warning = $state === 'running'
            && $idleSince !== ''
            && $idleElapsed >= $this->idleGraceSeconds;
        $shutdownIn = $warning
            ? max(0, $this->idleGraceSeconds + self::IDLE_WARNING_SECONDS - $idleElapsed)
            : null;

        return [
            'configured' => true,
            'mode' => 'ec2',
            'instance_id' => $this->instanceId,
            'region' => $this->region,
            'state' => $state,
            'session_active' => $active !== null,
            'session_status' => $active !== null ? (string)($active['status'] ?? '') : '',
            'idle_since' => $idleSince,
            'idle_elapsed_seconds' => $idleElapsed,
            'idle_grace_seconds' => $this->idleGraceSeconds,
            'warning_seconds' => self::IDLE_WARNING_SECONDS,
            'warning' => $warning,
            'shutdown_in_seconds' => $shutdownIn,
            'blockers' => $state === 'running'
                ? $this->shutdownBlockers(new MediaProcessingJobRepository($this->db))
                : [],
        ];
    }

    public function requestIdleStop(MediaProcessingJobRepository $jobs): array
    {
        return $this->withAdmissionLock(
            fn(): array => $this->requestIdleStopUnlocked($jobs)
        );
    }

    private function requestIdleStopUnlocked(MediaProcessingJobRepository $jobs): array
    {
        if ($this->instanceId === '' || $this->ec2 === null) {
            throw new RuntimeException('No hay una EC2 de alto rendimiento configurada.');
        }

        $this->reconcileOfficeSessions();
        $blockers = $this->shutdownBlockers($jobs);
        $this->logBlockerState($blockers);
        if ($blockers !== []) {
            throw new RuntimeException(
                'El nodo tiene tareas multimedia u Office activas y no se puede apagar. '
                . 'Bloqueos: ' . implode(', ', $blockers) . '.'
            );
        }

        // El endpoint de apagado interactivo sólo es válido durante la ventana
        // de aviso por inactividad. Así un usuario autenticado no puede usarlo
        // como un StopInstances genérico fuera del flujo previsto.
        $idle = $this->idleStatus();
        if (($idle['warning'] ?? false) !== true) {
            throw new RuntimeException('El nodo todavía no alcanzó el período de inactividad para apagarse.');
        }

        $instance = $this->ec2->getInstance($this->instanceId);
        $state = is_array($instance) ? Ec2Gateway::stateName($instance) : 'unknown';
        if ($state === 'stopped' || $state === 'stopping') {
            return $this->idleStatus();
        }
        if ($state !== 'running') {
            throw new RuntimeException('El nodo no está en un estado que permita apagarlo de forma segura.');
        }

        // Re-read after AWS I/O: a job, Office lease or heartbeat may have arrived.
        $active = $this->sessions->activeForInstance($this->instanceId);
        if ($this->hasShutdownBlockers($jobs) || !$this->idleDeadlineReached($active, false)) {
            if ($active !== null) $this->sessions->clearIdle((string)$active['session_id']);
            throw new RuntimeException('Se detectó actividad nueva; el apagado queda cancelado.');
        }
        if ($active !== null) {
            $this->recordSessionCost($active, 'interactive_idle_stop');
            if ($this->hasShutdownBlockers($jobs) || !$this->sessions->claimIdleStop(
                (string)$active['session_id'], (string)$active['idle_since'], $this->idleGraceSeconds
            )) throw new RuntimeException('Se detectó actividad nueva; el apagado queda cancelado.');
        }

        $this->ec2->stop($this->instanceId, false);
        return $this->idleStatus();
    }

    public function handleIdle(MediaProcessingJobRepository $jobs): void
    {
        $this->withAdmissionLock(function () use ($jobs): void {
            $this->handleIdleUnlocked($jobs);
        });
    }

    private function handleIdleUnlocked(MediaProcessingJobRepository $jobs): void
    {
        if ($this->instanceId === '' || $this->ec2 === null) return;

        $active = $this->sessions->activeForInstance($this->instanceId);
        if ($active === null || (string)($active['status'] ?? '') === 'stopping') {
            // An EC2 started outside ArcadeCloud (SSM, GitHub Actions, AWS console)
            // can have no tracked session. Recover it ONLY from this physical EC2,
            // not from remote PHP gateways. Stale "stopping" needs proof of reboot.
            $active = $this->recoverLocalBootSession($active);
        }
        if ($active === null) return;

        $this->reconcileOfficeSessions();

        // Una sesión "stopping" representa una orden de apagado ya emitida.
        // Nunca se reutiliza su IdleSince para enviar otro StopInstances. AWS
        // decide el estado físico; si ya está stopped cerramos el registro.
        if ((string)($active['status'] ?? '') === 'stopping') {
            $instance = $this->ec2->getInstance($this->instanceId);
            $state = is_array($instance) ? Ec2Gateway::stateName($instance) : 'unknown';
            if ($state === 'stopped') {
                $this->finalizeStoppedSession($active, 'stopped_detected');
            }
            return;
        }

        $blockers = $this->shutdownBlockers($jobs);
        $this->logBlockerState($blockers);
        if ($blockers !== []) {
            $this->sessions->clearIdle((string)$active['session_id']);
            return;
        }

        $idleSince = trim((string)($active['idle_since'] ?? ''));
        if ($idleSince === '') {
            $this->sessions->markIdle((string)$active['session_id']);
            return;
        }

        $idleTs = strtotime($idleSince . ' UTC');
        if (
            $idleTs === false
            || (time() - $idleTs) < ($this->idleGraceSeconds + self::IDLE_WARNING_SECONDS)
        ) {
            return;
        }

        $instance = $this->ec2->getInstance($this->instanceId);
        $state = is_array($instance) ? Ec2Gateway::stateName($instance) : 'unknown';

        if ($state === 'stopped') {
            $this->finalizeStoppedSession($active, 'already_stopped');
            return;
        }

        if ($state !== 'running') {
            return;
        }

        $active = $this->sessions->activeForInstance($this->instanceId);
        $blockers = $this->shutdownBlockers($jobs);
        $this->logBlockerState($blockers);
        if ($blockers !== [] || !$this->idleDeadlineReached($active, true)) {
            if ($active !== null) $this->sessions->clearIdle((string)$active['session_id']);
            return;
        }

        $this->recordSessionCost($active, 'auto_stop_idle');
        if ($this->hasShutdownBlockers($jobs) || !$this->sessions->claimIdleStop(
            (string)$active['session_id'], (string)$active['idle_since'],
            $this->idleGraceSeconds + self::IDLE_WARNING_SECONDS
        )) return;
        $this->ec2->stop($this->instanceId, false);
    }

    /**
     * Recover a missing session after an external EC2 start. This runs inside
     * the existing database admission mutex and never creates sessions for
     * remote gateways or machines whose IMDS identity cannot be verified.
     *
     * @param null|array<string,mixed> $active
     * @return null|array<string,mixed>
     */
    private function recoverLocalBootSession(?array $active): ?array
    {
        $role = strtolower(trim((string)(getenv('ARCADECLOUD_NODE_ROLE') ?: 'web')));
        $workerFlag = strtolower(trim((string)(getenv('ARCADECLOUD_MEDIA_WORKER') ?: '')));
        if (!in_array($role, ['media-worker', 'combined'], true)
            && !in_array($workerFlag, ['1', 'true', 'yes'], true)) {
            return $active;
        }

        $identity = ($this->localIdentity)();
        if (!is_array($identity) || (string)($identity['instance_id'] ?? '') !== $this->instanceId) {
            return $active;
        }

        $instance = $this->ec2?->getInstance($this->instanceId);
        if (!is_array($instance) || Ec2Gateway::stateName($instance) !== 'running') {
            return $active;
        }

        if ($active !== null) {
            if ((string)($active['status'] ?? '') !== 'stopping') return $active;
            $launch = $instance['LaunchTime'] ?? null;
            $launchTs = $launch instanceof \DateTimeInterface ? $launch->getTimestamp()
                : (is_string($launch) ? strtotime($launch) : false);
            $stopTs = strtotime((string)($active['stop_requested_at'] ?? '') . ' UTC');
            if ($launchTs === false || $stopTs === false || $launchTs <= $stopTs) {
                return $active;
            }
            $this->sessions->markStopped((string)$active['session_id']);
        }

        $recovered = $this->sessions->create(
            0,
            $this->instanceId,
            $this->region,
            (string)($instance['InstanceType'] ?? ''),
            $this->hourlyUsd
        );
        $this->sessions->markIdle((string)$recovered['session_id']);
        error_log('[ArcadeCloud media-node] sesión de inactividad recuperada tras encendido externo.');
        return $this->sessions->activeForInstance($this->instanceId);
    }

    private function withAdmissionLock(callable $callback): mixed
    {
        if ($this->instanceId === '') return $callback();

        return (new ComputeNodeAdmissionLock($this->db))
            ->synchronized($this->instanceId, $callback);
    }

    /** @return array<int,string> */
    private function shutdownBlockers(MediaProcessingJobRepository $jobs): array
    {
        $blockers = [];
        if ($jobs->hasActiveJobs() || $this->hasLocalMediaProcess()) {
            $blockers[] = 'multimedia_activa';
        }
        if ((new OfficeActivityProbe($this->db))->hasActiveSessions($this->instanceId)) {
            $blockers[] = 'office_activo';
        }
        return $blockers;
    }

    private function hasLocalMediaProcess(): bool
    {
        // The automatic stopper runs on the compute node; a gateway's local
        // process list cannot make claims about the remote workstation.
        $role = strtolower(trim((string)(getenv('ARCADECLOUD_NODE_ROLE') ?: 'web')));
        if (!in_array($role, ['media-worker', 'combined'], true)) return false;
        $processes = glob('/proc/[0-9]*/comm', GLOB_NOSORT);
        if ($processes === false || $processes === []) {
            throw new RuntimeException('No se pudieron comprobar los procesos multimedia; apagado bloqueado.');
        }
        foreach ($processes as $path) {
            $name = @file_get_contents($path);
            if ($name === false && is_file($path)) {
                throw new RuntimeException('No se pudo verificar un proceso local; apagado bloqueado.');
            }
            if (in_array(trim((string)$name), ['ffmpeg', 'ffprobe'], true)) return true;
        }
        return false;
    }

    private function hasShutdownBlockers(MediaProcessingJobRepository $jobs): bool
    {
        return $this->shutdownBlockers($jobs) !== [];
    }

    private function reconcileOfficeSessions(): void
    {
        if ($this->instanceId === '') return;
        $result = (new OfficeSessionReconciler($this->db))->reconcile($this->instanceId);
        if ((int)($result['reconciled'] ?? 0) > 0) {
            error_log(
                '[ArcadeCloud media-node] sesiones Office reconciliadas: '
                . (int)$result['reconciled']
            );
        }
    }

    /** @param array<int,string> $blockers */
    private function logBlockerState(array $blockers): void
    {
        sort($blockers, SORT_STRING);
        $signature = implode(',', $blockers);
        if ($signature === $this->lastBlockerSignature) return;
        $this->lastBlockerSignature = $signature;
        error_log(
            $signature === ''
                ? '[ArcadeCloud media-node] autoapagado libre de bloqueos; inicia/continúa contador.'
                : '[ArcadeCloud media-node] autoapagado pausado por: ' . $signature
        );
    }

    private function idleDeadlineReached(?array $session, bool $includeWarning): bool
    {
        if ($session === null || ($session['status'] ?? '') !== 'idle') return false;
        $since = trim((string)($session['idle_since'] ?? ''));
        $timestamp = $since !== '' ? strtotime($since . ' UTC') : false;
        return $timestamp !== false
            && time() - $timestamp >= $this->idleGraceSeconds + ($includeWarning ? self::IDLE_WARNING_SECONDS : 0);
    }

    private function remoteStatus(array $local): array
    {
        try {
            $instance = $this->ec2?->getInstance($this->instanceId);
        } catch (\Throwable $e) {
            error_log('[ArcadeCloud media-node] EC2 describe failed: ' . $e->getMessage());
            throw new RuntimeException(
                'No se pudo consultar la EC2 multimedia. El superusuario debe revisar región y permisos IAM.'
            );
        }
        if (!is_array($instance)) {
            throw new RuntimeException('La EC2 configurada para procesamiento multimedia no existe o no es accesible.');
        }

        $state = Ec2Gateway::stateName($instance);
        $active = $this->sessions->activeForInstance($this->instanceId);

        if ($active !== null && $state === 'stopped') {
            $this->finalizeStoppedSession($active, 'stopped_detected');
            $active = null;
        }

        return [
            'configured' => true,
            'mode' => 'ec2',
            'selection' => 'remote_fallback',
            'instance_id' => $this->instanceId,
            'region' => $this->region,
            'instance_type' => (string)($instance['InstanceType'] ?? ''),
            'state' => $state,
            'authorization_required' => $state === 'stopped',
            'can_enqueue' => in_array($state, ['running','pending','stopped'], true),
            'idle_grace_seconds' => $this->idleGraceSeconds,
            'idle_warning_seconds' => self::IDLE_WARNING_SECONDS,
            'hourly_usd' => $this->hourlyUsd,
            'cost_configured' => $this->hourlyUsd !== null,
            'session_active' => $active !== null,
            'local_preflight' => $local,
            'message' => 'El nodo local no pasó el preflight; se seleccionó la EC2 multimedia configurada.',
        ];
    }

    private function localStatus(int $sourceBytes = 0): array
    {
        $role = strtolower(trim((string)(getenv('ARCADECLOUD_NODE_ROLE') ?: 'web')));
        $ffmpeg = $this->findExecutable('ffmpeg');
        $ffprobe = $this->findExecutable('ffprobe');
        $capacity = $this->localCapacity($sourceBytes);
        $workerActive = $this->localWorkerActive();

        $base = array_merge($capacity, [
            'configured' => true,
            'mode' => 'local',
            'selection' => 'local_preflight',
            'authorization_required' => false,
            'role' => $role,
            'role_is_informational' => true,
            'worker_active' => $workerActive,
            'ffmpeg_available' => $ffmpeg !== null,
            'ffprobe_available' => $ffprobe !== null,
            'idle_grace_seconds' => $this->idleGraceSeconds,
            'hourly_usd' => null,
            'cost_configured' => true,
            'session_active' => false,
        ]);

        if (($capacity['capacity_sufficient'] ?? false) !== true) {
            $reasons = [];
            if (($capacity['hardware_sufficient'] ?? false) !== true) {
                $reasons[] = 'mínimo de 2 vCPU y una instancia de 8 GiB de RAM';
            }
            if (($capacity['temporary_space_sufficient'] ?? true) !== true) {
                $reasons[] = 'espacio temporal suficiente para este archivo';
            }
            return array_merge($base, [
                'state' => 'insufficient_capacity',
                'can_enqueue' => false,
                'message' => 'El nodo local no cumple ' . implode(' ni ', $reasons) . '. Se buscará un worker remoto si está configurado.',
            ]);
        }

        if ($ffmpeg === null || $ffprobe === null) {
            $missing = [];
            if ($ffmpeg === null) $missing[] = 'ffmpeg';
            if ($ffprobe === null) $missing[] = 'ffprobe';
            return array_merge($base, [
                'state' => 'dependency_missing',
                'can_enqueue' => false,
                'message' => 'El nodo local tiene capacidad, pero faltan herramientas multimedia: ' . implode(', ', $missing) . '. Se buscará un worker remoto si está configurado.',
            ]);
        }

        if (!$workerActive) {
            return array_merge($base, [
                'state' => 'worker_inactive',
                'can_enqueue' => false,
                'message' => 'El nodo local tiene capacidad y FFmpeg/FFprobe, pero arcadecloud-media-worker.service no está activo. Se buscará un worker remoto si está configurado.',
            ]);
        }

        return array_merge($base, [
            'state' => 'running',
            'can_enqueue' => true,
            'message' => 'Worker multimedia local disponible: capacidad real, espacio, FFmpeg/FFprobe y servicio activo verificados.',
        ]);
    }

    private function localCapacity(int $sourceBytes = 0): array
    {
        $cpu = 0;
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo) && $cpuInfo !== '') {
            preg_match_all('/^processor\s*:/m', $cpuInfo, $matches);
            $cpu = count($matches[0] ?? []);
        }

        $memoryBytes = 0;
        $memoryAvailableBytes = 0;
        $memInfo = @file_get_contents('/proc/meminfo');
        if (is_string($memInfo)) {
            if (preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $memInfo, $match) === 1) {
                $memoryBytes = (int)$match[1] * 1024;
            }
            if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/im', $memInfo, $match) === 1) {
                $memoryAvailableBytes = (int)$match[1] * 1024;
            }
        }

        $hardwareSufficient = $cpu >= self::MIN_VCPU
            && $memoryBytes >= self::MIN_VISIBLE_MEMORY_BYTES;

        $tmpRoot = rtrim((string)(getenv('ARCADECLOUD_MEDIA_TMP') ?: '/var/lib/arcadecloud-media/tmp'), '/');
        $diskPath = $this->existingDiskPath($tmpRoot);
        $free = $diskPath !== null ? @disk_free_space($diskPath) : false;
        $freeBytes = $free === false ? null : (int)$free;
        $requiredBytes = $sourceBytes > 0
            ? (int)ceil($sourceBytes * self::TEMP_SPACE_MULTIPLIER)
            : 0;
        $temporarySpaceSufficient = $sourceBytes <= 0
            || ($freeBytes !== null && $freeBytes >= $requiredBytes);

        return [
            'vcpu' => $cpu,
            'memory_bytes' => $memoryBytes,
            'memory_available_bytes' => $memoryAvailableBytes,
            'capacity_sufficient' => $hardwareSufficient && $temporarySpaceSufficient,
            'hardware_sufficient' => $hardwareSufficient,
            'temporary_space_sufficient' => $temporarySpaceSufficient,
            'temporary_space_free_bytes' => $freeBytes,
            'temporary_space_required_bytes' => $requiredBytes,
            'source_bytes_checked' => max(0, $sourceBytes),
            'minimum_vcpu' => self::MIN_VCPU,
            'minimum_memory_bytes' => self::MIN_VISIBLE_MEMORY_BYTES,
        ];
    }

    private function localWorkerActive(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        $systemctl = $this->findExecutable('systemctl');
        if ($systemctl === null) {
            return false;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];
        $process = @proc_open(
            [$systemctl, 'is-active', '--quiet', 'arcadecloud-media-worker.service'],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return false;
        }

        return proc_close($process) === 0;
    }

    private function existingDiskPath(string $path): ?string
    {
        $candidate = $path !== '' ? $path : '/';
        while (!is_dir($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return null;
            }
            $candidate = $parent;
        }
        return $candidate;
    }

    private function findExecutable(string $binary): ?string
    {
        if (!preg_match('/^[a-z0-9._-]+$/i', $binary)) {
            return null;
        }
        $paths = explode(PATH_SEPARATOR, (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'));
        foreach ($paths as $path) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function finalizeStoppedSession(array $session, string $reason): void
    {
        if ((string)($session['stop_requested_at'] ?? '') === '') {
            $this->recordSessionCost($session, $reason);
        }
        $this->sessions->markStopped((string)$session['session_id']);
    }

    private function recordSessionCost(array $session, string $reason): void
    {
        $startedAt = strtotime((string)($session['started_at'] ?? '') . ' UTC');
        $elapsed = $startedAt !== false ? max(1, time() - $startedAt) : 1;
        $billable = max(60, $elapsed);
        $userId = (int)($session['started_by_user_id'] ?? 0);
        if ($userId <= 0) return;

        $this->activity->success(
            $userId,
            'media-node-session',
            'EC2',
            null,
            ['ec2.media_worker_second' => $billable],
            null,
            [
                'instance_id' => $this->instanceId,
                'instance_type' => (string)($session['instance_type'] ?? ''),
                'elapsed_seconds' => $elapsed,
                'billing_reference_seconds' => $billable,
                'hourly_usd' => $this->hourlyUsd,
                'stop_reason' => $reason,
            ],
            ActivityCostRecorder::correlation('media-node', (string)$session['session_id'])
        );
    }
}
