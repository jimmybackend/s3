<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Console;

use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use ArcadeCloud\Drive\Admin\ServerMaintenanceJobStore;
use ArcadeCloud\Drive\Admin\ServerMaintenanceService;
use ArcadeCloud\Drive\Core\ApplicationKernel;

final class ServerMaintenanceWorkerCommand
{
    public function run(array $argv): int
    {
        $jobId = strtolower(trim((string)($argv[1] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) return 2;

        try {
            $app = ApplicationKernel::app();
            (new ServerMaintenanceService(
                $app,
                new ServerMaintenanceJobStore(),
                new PrivilegedServerHelper()
            ))->run($jobId);
            return 0;
        } catch (\Throwable $error) {
            error_log('[ArcadeCloud maintenance-worker] ' . $error->getMessage());
            return 1;
        }
    }
}
