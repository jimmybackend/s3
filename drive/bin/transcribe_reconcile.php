<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Activity\TranscriptionReconciler;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\TranscriptionFileService;
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
    $transcribe = \Config::getTranscribe();

    $files = new TranscriptionFileService(
        new FileRecordLocator($db),
        new GeneratedFileRepository($db),
        $app->s3(),
        $transcribe,
        $app->bucket()
    );

    $worker = new TranscriptionReconciler(
        $db,
        $files,
        $transcribe,
        ActivityCostRecorder::fromDatabase($db)
    );

    $result = $worker->run($limit);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    // Los errores de un job concreto (por ejemplo un resultado histórico ya
    // inaccesible) son advertencias de reconciliación, no un fallo del worker.
    // Sólo las excepciones de infraestructura/arranque deben tumbar systemd.
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Transcribe reconcile error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
