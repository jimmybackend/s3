<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Sync\NodeSyncService;
use ArcadeCloud\Drive\Sync\S3SyncService;
use ArcadeCloud\Drive\Sync\SyncJobStore;
use ArcadeCloud\Drive\Sync\SyncRepository;

$app = ApplicationKernel::app();
$jobs = new SyncJobStore();
$nodeLockPath = sys_get_temp_dir() . '/arcadecloud-node-sync.lock';
$nodeLock = @fopen($nodeLockPath, 'c+');

if (!is_resource($nodeLock)) {
    fwrite(STDERR, "No se pudo crear el lock de sincronización del nodo.\n");
    exit(3);
}

if (!flock($nodeLock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Ya existe una sincronización completa del nodo en curso.\n");
    fclose($nodeLock);
    exit(4);
}

try {
    $sync = new S3SyncService(
        new SyncRepository($app->db()),
        $app->s3(),
        $app->bucket(),
        $app->userStoragePath(),
        $app->storageObjectNameCodec()
    );

    $service = new NodeSyncService(
        $app->db(),
        $sync,
        $jobs
    );

    $result = $service->synchronizeAll(
        static function (array $event): void {
            $line = json_encode(
                ['type' => 'progress'] + $event,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if (is_string($line)) {
                fwrite(STDOUT, $line . PHP_EOL);
            }
        }
    );

    $json = json_encode(
        ['type' => 'summary'] + $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if (is_string($json)) {
        fwrite(STDOUT, $json . PHP_EOL);
    }

    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Node sync error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($nodeLock, LOCK_UN);
    fclose($nodeLock);
}
