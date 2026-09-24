<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Console\MediaProcessingWorkerCommand;
use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;

$app = ApplicationKernel::app();
$jobs = new MediaProcessingJobRepository($app->db());
$generated = new GeneratedFileRepository($app->db());

$loop = in_array('--loop', $argv, true);
$sleep = 5;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sleep=')) {
        $sleep = max(1, min(60, (int)substr($arg, 8)));
    }
}

try {
    exit((new MediaProcessingWorkerCommand($app, $jobs, $generated))->run($loop, $sleep));
} catch (Throwable $e) {
    fwrite(STDERR, '[media-worker] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
