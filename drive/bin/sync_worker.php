<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$userId = (int)($argv[1] ?? 0);
$jobId = (string)($argv[2] ?? '');

if ($userId <= 0 || !preg_match('/^[a-f0-9]{32}$/', $jobId)) {
    exit(3);
}

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Sync\S3SyncService;
use ArcadeCloud\Drive\Sync\SyncJobStore;
use ArcadeCloud\Drive\Sync\SyncRepository;

$store = new SyncJobStore();

$lockHandle = fopen($store->lockPath($userId), 'c+');

if (!$lockHandle) {
    $store->update($userId, $jobId, [
        'state' => 'error',
        'message' => 'No se pudo crear lock.',
    ]);
    exit(4);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $store->update($userId, $jobId, [
        'state' => 'error',
        'message' => 'Ya existe una sincronización en curso.',
    ]);
    exit(5);
}

try {
    $store->update($userId, $jobId, [
        'state' => 'running',
        'message' => 'Iniciando sincronización',
    ]);

    $app = ApplicationKernel::app();

    $service = new S3SyncService(
        new SyncRepository($app->db()),
        $app->s3(),
        $app->bucket(),
        $app->userStoragePath(),
        $app->storageObjectNameCodec()
    );

    $syncId = null;
    $token = null;

    $batch = 0;
    $files = 0;
    $folders = 0;

    do {
        $batch++;

        $result = $service->synchronizeBatch(
            $userId,
            $syncId,
            $token
        );

        $syncId = (string)$result['sync_id'];
        $token = $result['next_token'] ?: null;

        $files += (int)($result['batch_files'] ?? 0);
        $folders += (int)($result['batch_folders'] ?? 0);

        $store->update($userId, $jobId, [
            'state' => 'running',
            'batch' => $batch,
            'files' => $files,
            'folders' => $folders,
            'message' =>
                'Lote ' . $batch .
                ' · ' . $files . ' archivos',
        ]);

    } while (empty($result['done']));

    $store->update($userId, $jobId, [
        'state' => 'done',
        'batch' => $batch,
        'files' => $files,
        'folders' => $folders,
        'files_removed' => (int)($result['files_removed'] ?? 0),
        'folders_removed' => (int)($result['folders_removed'] ?? 0),
        'message' => 'Sincronización completada',
        'finished_at' => date('c'),
    ]);

} catch (Throwable $error) {
    $store->update($userId, $jobId, [
        'state' => 'error',
        'message' => $error->getMessage(),
        'finished_at' => date('c'),
    ]);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
