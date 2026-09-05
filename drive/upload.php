<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

(new \ArcadeCloud\Drive\Http\Controller\LegacyUploadController(
    \ArcadeCloud\Drive\Core\ApplicationKernel::app(),
    \ArcadeCloud\Drive\Http\Request::fromGlobals()
))->multi();
