<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Activity\PollyTaskReconciler;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\PollyFileService;
use ArcadeCloud\Drive\Core\ApplicationKernel;

require dirname(__DIR__) . '/app_bootstrap.php';

$limit = 250;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with((string)$arg, '--limit=')) {
        $value = (int)substr((string)$arg, 8);
        if ($value > 0) {
            $limit = min(1000, $value);
        }
    }
}

try {
    $app = ApplicationKernel::app();
    $db = $app->db();
    $files = new PollyFileService(
        new FileRecordLocator($db),
        new GeneratedFileRepository($db),
        $app->s3(),
        \Config::getPolly(),
        $app->bucket()
    );

    $worker = new PollyTaskReconciler(
        $db,
        $files,
        ActivityCostRecorder::fromDatabase($db)
    );

    $result = $worker->run($limit);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    // Un error de una tarea concreta no debe apagar el timer. Las excepciones
    // de infraestructura/arranque sí salen con código 1.
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Polly reconcile error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
