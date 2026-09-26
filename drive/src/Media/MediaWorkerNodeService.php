<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Aws\Ec2Gateway;
use mysqli;
use RuntimeException;

final class MediaWorkerNodeService
{
    private const MIN_VCPU = 2;
    // Una instancia de 8 GiB expone algo menos a Linux por memoria reservada.
    private const MIN_VISIBLE_MEMORY_BYTES = 7 * 1024 * 1024 * 1024;

    private string $instanceId;
    private string $region;
    private int $idleGraceSeconds;
    private ?float $hourlyUsd;
    private ?Ec2Gateway $ec2 = null;
    private MediaWorkerNodeSessionRepository $sessions;
    private ActivityCostRecorder $activity;

    public function __construct(private mysqli $db)
    {
        $this->instanceId = trim((string)(getenv('ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID') ?: ''));
        $this->region = trim((string)(getenv('ARCADECLOUD_MEDIA_WORKER_REGION') ?: getenv('AWS_REGION') ?: 'us-east-1'));
        $this->idleGraceSeconds = max(
            60,
            min(3600, (int)(getenv('ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS') ?: 300))
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

    public function status(): array
    {
        if ($this->instanceId === '' || $this->ec2 === null) {
            return $this->localStatus();
        }

        try {
            $instance = $this->ec2->getInstance($this->instanceId);
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
            'instance_id' => $this->instanceId,
            'region' => $this->region,
            'instance_type' => (string)($instance['InstanceType'] ?? ''),
            'state' => $state,
            'authorization_required' => $state === 'stopped',
            'can_enqueue' => in_array($state, ['running','pending','stopped'], true),
            'idle_grace_seconds' => $this->idleGraceSeconds,
            'hourly_usd' => $this->hourlyUsd,
            'cost_configured' => $this->hourlyUsd !== null,
            'session_active' => $active !== null,
        ];
    }

    public function prepareForWork(int $userId, bool $authorizedStart): array
    {
        $status = $this->status();
        if (($status['configured'] ?? false) !== true) {
            throw new RuntimeException(
                '[MEDIA_WORKER_UNAVAILABLE] ' . (string)($status['message']
                    ?? 'No hay un nodo multimedia configurado para esta instalación.')
            );
        }

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

    public function markBusy(): void
    {
        if ($this->instanceId === '') return;
        $active = $this->sessions->activeForInstance($this->instanceId);
        if ($active !== null) {
            $this->sessions->markRunning((string)$active['session_id']);
        }
    }

    public function handleIdle(MediaProcessingJobRepository $jobs): void
    {
        if ($this->instanceId === '' || $this->ec2 === null) return;

        $active = $this->sessions->activeForInstance($this->instanceId);
        if ($active === null) return;

        if ($jobs->hasActiveJobs()) {
            $this->sessions->clearIdle((string)$active['session_id']);
            return;
        }

        $idleSince = trim((string)($active['idle_since'] ?? ''));
        if ($idleSince === '') {
            $this->sessions->markIdle((string)$active['session_id']);
            return;
        }

        $idleTs = strtotime($idleSince . ' UTC');
        if ($idleTs === false || (time() - $idleTs) < $this->idleGraceSeconds) {
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

        $this->recordSessionCost($active, 'auto_stop_idle');
        $this->sessions->markStopRequested((string)$active['session_id']);
        $this->ec2->stop($this->instanceId, false);
    }

    private function localStatus(): array
    {
        $role = strtolower(trim((string)(getenv('ARCADECLOUD_NODE_ROLE') ?: 'web')));
        if (!in_array($role, ['media-worker', 'combined'], true)) {
            return [
                'configured' => false,
                'mode' => 'none',
                'state' => 'unconfigured',
                'authorization_required' => false,
                'can_enqueue' => false,
                'role' => $role,
                'message' => 'Este nodo tiene rol web y no hay una EC2 multimedia configurada. Configura ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID o asigna un nodo media-worker/combined.',
            ];
        }

        $ffmpeg = $this->findExecutable('ffmpeg');
        $ffprobe = $this->findExecutable('ffprobe');
        $capacity = $this->localCapacity();

        if ($ffmpeg === null || $ffprobe === null) {
            $missing = [];
            if ($ffmpeg === null) $missing[] = 'ffmpeg';
            if ($ffprobe === null) $missing[] = 'ffprobe';
            return array_merge($capacity, [
                'configured' => true,
                'mode' => 'local',
                'state' => 'dependency_missing',
                'authorization_required' => false,
                'can_enqueue' => false,
                'role' => $role,
                'ffmpeg_available' => $ffmpeg !== null,
                'ffprobe_available' => $ffprobe !== null,
                'message' => 'El nodo multimedia no tiene FFmpeg/FFprobe disponibles. Faltan: ' . implode(', ', $missing) . '. Ejecuta la reconciliación/instalador del nodo; no se instalarán paquetes desde esta petición web.',
            ]);
        }

        if (($capacity['capacity_sufficient'] ?? false) !== true) {
            return array_merge($capacity, [
                'configured' => true,
                'mode' => 'local',
                'state' => 'insufficient_capacity',
                'authorization_required' => false,
                'can_enqueue' => false,
                'role' => $role,
                'ffmpeg_available' => true,
                'ffprobe_available' => true,
                'message' => 'El nodo multimedia local no cumple el mínimo soportado de 2 vCPU y una instancia de 8 GiB de RAM. Usa un media worker con capacidad suficiente.',
            ]);
        }

        return array_merge($capacity, [
            'configured' => true,
            'mode' => 'local',
            'state' => 'running',
            'authorization_required' => false,
            'can_enqueue' => true,
            'role' => $role,
            'ffmpeg_available' => true,
            'ffprobe_available' => true,
            'idle_grace_seconds' => $this->idleGraceSeconds,
            'hourly_usd' => null,
            'cost_configured' => true,
            'session_active' => false,
            'message' => 'Worker multimedia local disponible.',
        ]);
    }

    private function localCapacity(): array
    {
        $cpu = 0;
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo) && $cpuInfo !== '') {
            preg_match_all('/^processor\s*:/m', $cpuInfo, $matches);
            $cpu = count($matches[0] ?? []);
        }

        $memoryBytes = 0;
        $memInfo = @file_get_contents('/proc/meminfo');
        if (is_string($memInfo) && preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $memInfo, $match) === 1) {
            $memoryBytes = (int)$match[1] * 1024;
        }

        // Si el SO no expone una métrica, no fingimos capacidad: un worker
        // compatible debe poder demostrarla antes de aceptar el job.
        $sufficient = $cpu >= self::MIN_VCPU
            && $memoryBytes >= self::MIN_VISIBLE_MEMORY_BYTES;

        return [
            'vcpu' => $cpu,
            'memory_bytes' => $memoryBytes,
            'capacity_sufficient' => $sufficient,
            'minimum_vcpu' => self::MIN_VCPU,
            'minimum_memory_bytes' => self::MIN_VISIBLE_MEMORY_BYTES,
        ];
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
