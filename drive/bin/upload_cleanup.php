<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

$command = new \ArcadeCloud\Drive\Console\UploadCleanupCommand(
    \ArcadeCloud\Drive\Core\ApplicationKernel::app()
);

exit($command->run($argv));
