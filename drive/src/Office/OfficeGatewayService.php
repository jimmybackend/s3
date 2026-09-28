<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use ArcadeCloud\Drive\Admin\FastDriveWakeService;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use ArcadeCloud\Drive\Media\MediaWorkerNodeService;

final class OfficeGatewayService
{
    private FastDriveWakeService $wake;
    private OfficeWorkstationClient $workstation;

    public function __construct(private DriveApplication $app)
    {
        $this->wake = new FastDriveWakeService($app);
        $this->workstation = new OfficeWorkstationClient();
    }

    public function consumeLaunch(string $token): int
    {
        return (new OfficeLaunchTokenRepository($this->app->db()))->consume($token);
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
