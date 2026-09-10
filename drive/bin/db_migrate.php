#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

exit((new \ArcadeCloud\Drive\Console\SqlMigrationCommand(
    \ArcadeCloud\Drive\Core\ApplicationKernel::app()
))->run($argv));
