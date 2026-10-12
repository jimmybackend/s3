<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Upload/UploadTaskStore.php';

use ArcadeCloud\Drive\Upload\UploadTaskStore;

$dir = sys_get_temp_dir() . '/upload-reconcile-contract-' . bin2hex(random_bytes(6));
try {
    $store = new UploadTaskStore($dir);
    $store->put(17, 'with-key', [
        'status' => 'running', 'upload_mode' => 'local_put',
        'object_key' => 'Data/owner17/file.bin', 'bytes_total' => 9,
    ]);
    $store->put(17, 'without-key', ['status' => 'running', 'upload_mode' => 'local_put']);
    $store->put(18, 'multipart', [
        'status' => 'running', 'upload_mode' => 'chunked',
        'object_key' => 'Data/owner18/file.bin',
    ]);
    $store->put(17, 'finished', [
        'status' => 'completed', 'upload_mode' => 'local_put',
        'object_key' => 'Data/owner17/done.bin',
    ]);
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        $job = json_decode((string)file_get_contents($path), true);
        if ($job['id'] !== 'with-key') continue;
        $job['updated_at'] = gmdate('c', time() - 600);
        file_put_contents($path, json_encode($job, JSON_THROW_ON_ERROR));
    }
    $stale = $store->staleLocalUploads();
    if (count($stale) !== 1 || $stale[0]['id'] !== 'with-key'
        || $stale[0]['user_id'] !== 17) {
        throw new RuntimeException('El reconciliador incluyó tareas no elegibles.');
    }
    echo "OK: sólo se concilian subidas locales antiguas con key servidor.\n";
} finally {
    foreach (glob($dir . '/*.json') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}
