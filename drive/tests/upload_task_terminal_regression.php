<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Upload/UploadTaskStore.php';

use ArcadeCloud\Drive\Upload\UploadTaskStore;

$dir = sys_get_temp_dir() . '/upload-task-store-regression-' . bin2hex(random_bytes(6));
try {
    $store = new UploadTaskStore($dir);
    $store->put(1, 'local-put-1', ['status' => 'running', 'progress' => 96]);
    $store->put(1, 'local-put-1', ['status' => 'completed', 'progress' => 100, 'detail' => 'Confirmado']);
    $store->put(1, 'local-put-1', ['status' => 'running', 'progress' => 96, 'detail' => 'Señal tardía']);
    $store->put(1, 'local-put-1', ['status' => 'failed', 'progress' => 100, 'detail' => 'Timeout del navegador']);

    $jobs = $store->recentForUser(1);
    if (count($jobs) !== 1 || $jobs[0]['status'] !== 'completed'
        || $jobs[0]['progress'] !== 100 || $jobs[0]['detail'] !== 'Confirmado') {
        throw new RuntimeException('La confirmación final fue sobrescrita.');
    }
    echo "OK: las señales atrasadas no reabren una subida completada.\n";
} finally {
    foreach (glob($dir . '/*.json') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}
