<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Aws\Ec2Gateway;
use mysqli;
use RuntimeException;

final class MediaWorkerNodeService
{
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
            return [
                'configured' => false,
                'state' => 'unconfigured',
                'authorization_required' => false,
                'message' => 'No hay una EC2 multimedia configurada para encendido bajo demanda.',
            ];
        }

        $instance = $this->ec2->getInstance($this->instanceId);
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
            'instance_id' => $this->instanceId,
            'region' => $this->region,
            'instance_type' => (string)($instance['InstanceType'] ?? ''),
            'state' => $state,
            'authorization_required' => $state === 'stopped',
            'can_enqueue' => in_array($state, ['running','pending','stopped'], true),
            'idle_grace_seconds' => $this->idleGraceSeconds,
            'hourly_usd' => $this->hourlyUsd,
            'session_active' => $active !== null,
        ];
    }

    public function prepareForWork(int $userId, bool $authorizedStart): array
    {
        $status = $this->status();
        if (($status['configured'] ?? false) !== true) {
            return $status;
        }

        $state = (string)($status['state'] ?? 'unknown');
        if (in_array($state, ['running','pending'], true)) {
            return $status;
        }

        if ($state === 'stopped') {
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
                throw $e;
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
