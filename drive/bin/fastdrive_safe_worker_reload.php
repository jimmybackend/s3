<?php
declare(strict_types=1);

// Applied only on the compute node after a clean fast-forward update.
// Never restart a worker that may hold multimedia jobs or Office sessions.
// No user-supplied command strings and no credentials in output.

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Admin\ServerTaskActivityProbe;
use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\System\ComputeNodeAdmissionLock;
use ArcadeCloud\Drive\System\Ec2InstanceIdentityService;

try {
    $identity = (new Ec2InstanceIdentityService())->current();
    $instanceId = (string)($identity['instance_id'] ?? '');
    if (!preg_match('/^i-[0-9a-f]{8,17}$/', $instanceId)) {
        throw new RuntimeException('No se pudo verificar la identidad física del nodo.');
    }

    $app = ApplicationKernel::app();
    (new ComputeNodeAdmissionLock($app->db()))->synchronized($instanceId, static function () use ($app): void {
        $probe = (new ServerTaskActivityProbe($app))->summary();
        if ((int)($probe['active'] ?? 0) > 0) {
            echo "ARCADECLOUD_WORKER_RELOAD=deferred_busy_tasks\n";
            return;
        }
        // Reloading only the media worker does NOT restart the separate
        // Workstation/Guacamole desktop. The shared task/Office probe above
        // has already rejected unsafe jobs and unsynced documents.
        foreach (['ffmpeg','ffprobe','rar','unrar','zip','unzip','git','7z'] as $name) {
            $output = [];
            $exit = 1;
            exec('/usr/bin/pgrep -x ' . escapeshellarg($name) . ' >/dev/null 2>&1', $output, $exit);
            if ($exit === 0) {
                echo "ARCADECLOUD_WORKER_RELOAD=deferred_local_process\n";
                return;
            }
        }
        // Restart only the media worker; never restart Guacamole, MySQL,
        // Nginx, Docker, PHP-FPM or the interactive workstation.
        $output = [];
        $exit = 1;
        exec('/usr/bin/timeout 30 /usr/bin/systemctl restart arcadecloud-media-worker.service >/dev/null 2>&1', $output, $exit);
        if ($exit !== 0) throw new RuntimeException('No se pudo reiniciar el worker de manera segura.');
        echo "ARCADECLOUD_WORKER_RELOAD=success\n";
    });
} catch (Throwable $e) {
    error_log('[ArcadeCloud GitOps] no se pudo reconciliar worker: ' . $e->getMessage());
    echo "ARCADECLOUD_WORKER_RELOAD=failed_safe\n";
    exit(1);
}
