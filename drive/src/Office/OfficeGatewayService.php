<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use ArcadeCloud\Drive\Admin\FastDriveWakeService;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use ArcadeCloud\Drive\Media\MediaWorkerNodeService;
use ArcadeCloud\Drive\View\FileViewHelper;
use ArcadeCloud\Drive\System\ComputeNodeAdmissionLock;
use RuntimeException;

final class OfficeGatewayService
{
    private FastDriveWakeService $wake;
    private OfficeWorkstationClient $workstation;
    private OfficeSessionLeaseRepository $leases;

    public function __construct(private DriveApplication $app)
    {
        $this->wake = new FastDriveWakeService($app);
        $this->workstation = new OfficeWorkstationClient();
        $this->leases = new OfficeSessionLeaseRepository($app->db());
    }

    public function consumeLaunch(string $token): int
    {
        return (new OfficeLaunchTokenRepository($this->app->db()))->consume($token);
    }

    /** @return array{user_id:int,file_id:?int} */
    public function consumeLaunchContext(string $token): array
    {
        return (new OfficeLaunchTokenRepository($this->app->db()))->consumeContext($token);
    }

    /** @return array{session_id:string,control_token:string,name:string} */
    public function createDocumentSession(int $userId, int $fileId, string $instanceId): array
    {
        $row = $this->app->fileRecordRepository()->requireByRef($userId, $fileId, true);
        if (FileViewHelper::isLocked($row)) {
            throw new RuntimeException('Desbloquea el archivo antes de abrirlo con Office.');
        }
        $name = trim((string)($row['Nombre'] ?? ''));
        $ext = FileViewHelper::extension($name);
        if (!in_array($ext, ['doc','docx','odt','rtf','xls','xlsx','ods','ppt','pptx','odp'], true)) {
            throw new RuntimeException('Este archivo no es compatible con ArcadeCloud Office.');
        }

        $session = (new OfficeDocumentSessionRepository($this->app->db()))->create(
            $userId,
            $fileId,
            $instanceId,
            (string)($row['_key'] ?? ''),
            $name
        );
        $session['name'] = $name;
        return $session;
    }

    public function prepareDocument(string $privateIp, string $sessionId, string $token): array
    {
        return $this->workstation->prepareDocument($privateIp, $sessionId, $token);
    }

    public function syncDocument(string $privateIp, string $sessionId, string $token): array
    {
        return $this->workstation->syncDocument($privateIp, $sessionId, $token);
    }

    public function closeDocument(string $privateIp, string $sessionId, string $token): array
    {
        return $this->workstation->closeDocument($privateIp, $sessionId, $token);
    }

    /** @return array<string,mixed> */
    public function nodeStatus(): array
    {
        return $this->wake->status();
    }

    /** @return array{state:string,changed:bool} */
    public function authorizeAndStart(string $password, string $ipAddress): array
    {
        return $this->wake->authorizeAndStart($password, $ipAddress);
    }

    public function claimOfficeSession(int $userId, string $instanceId, string $sessionKey): void
    {
        $this->leases->claim($userId, $instanceId, $sessionKey);
    }

    public function assertOfficeSession(int $userId, string $instanceId, string $sessionKey): void
    {
        $this->leases->assertOwner($userId, $instanceId, $sessionKey);
    }

    public function touchOfficeSession(int $userId, string $instanceId, string $sessionKey): void
    {
        $this->leases->touch($userId, $instanceId, $sessionKey);
    }

    public function releaseOfficeSession(int $userId, string $instanceId, string $sessionKey): void
    {
        $this->leases->release($userId, $instanceId, $sessionKey);
    }

    /**
     * @return array{active:bool,media_busy:bool,control:array<string,mixed>|null}
     */
    public function prepareWorkstation(string $privateIp): array
    {
        $jobs = new MediaProcessingJobRepository($this->app->db());
        $mediaBusy = $jobs->hasActiveJobs();

        $control = $this->workstation->status($privateIp);
        $active = (bool)($control['active'] ?? false);

        if (!$active && !$mediaBusy) {
            $control = $this->workstation->start($privateIp);
            $active = (bool)($control['active'] ?? false);
        }

        return [
            'active' => $active,
            'media_busy' => $mediaBusy,
            'control' => $control,
        ];
    }

    /**
     * Atomically verifies the EC2 is still running, prepares Workstation and
     * publishes the Office lease before an idle/manual stop can proceed.
     *
     * @return array{active:bool,media_busy:bool,control:array<string,mixed>|null}
     */
    public function prepareAndClaimWorkstation(
        int $userId,
        string $instanceId,
        string $sessionKey,
        string $privateIp
    ): array {
        return (new ComputeNodeAdmissionLock($this->app->db()))
            ->synchronized($instanceId, function () use ($userId, $instanceId, $sessionKey, $privateIp): array {
                $node = $this->wake->status();
                if (
                    (string)($node['state'] ?? '') !== 'running'
                    || (string)($node['instance_id'] ?? '') !== $instanceId
                ) {
                    throw new RuntimeException('El nodo Office cambió de estado; vuelve a intentarlo.');
                }

                $prepared = $this->prepareWorkstation($privateIp);
                if (($prepared['active'] ?? false) === true) {
                    $this->claimOfficeSession($userId, $instanceId, $sessionKey);
                }
                return $prepared;
            });
    }

    /** @return array<string,mixed> */
    public function touchActivity(int $userId): array
    {
        return (new MediaWorkerNodeService($this->app->db()))
            ->touchInteractiveActivity($userId);
    }

    /** @return array<string,mixed> */
    public function idleStatus(): array
    {
        $node = new MediaWorkerNodeService($this->app->db());
        $jobs = new MediaProcessingJobRepository($this->app->db());
        $node->handleIdle($jobs);
        return $node->idleStatus();
    }
}
