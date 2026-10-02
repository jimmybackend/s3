<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app_bootstrap.php';
exit((new \ArcadeCloud\Drive\Console\ActivityRetentionCommand(
    \ArcadeCloud\Drive\Core\ApplicationKernel::app()->db()
))->run($argv));
