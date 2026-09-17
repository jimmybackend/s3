<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\ArcadeCloudUpdateController;
use ArcadeCloud\Drive\Http\Request;

(new ArcadeCloudUpdateController(ApplicationKernel::app(), Request::fromGlobals()))->api();
