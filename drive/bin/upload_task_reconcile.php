#!/usr/bin/env php
<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Upload\UploadTaskReconciler;

require dirname(__DIR__) . '/app_bootstrap.php';

try {
    $app = ApplicationKernel::app();
    $reconciler = new UploadTaskReconciler(
        $app->uploadTaskStore(), $app->db(), $app->s3(), $app->bucket()
    );
    $result = $reconciler->run(20);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[Upload reconcile] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
