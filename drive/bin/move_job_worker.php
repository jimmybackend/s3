<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require dirname(__DIR__) . '/app_bootstrap.php';

exit((new \ArcadeCloud\Drive\Console\MoveJobWorkerCommand())->run($argv));
