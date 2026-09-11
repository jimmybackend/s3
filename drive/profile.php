<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\UserProfileController;
use ArcadeCloud\Drive\Http\Request;

(new UserProfileController(ApplicationKernel::app(), Request::fromGlobals()))->api();
