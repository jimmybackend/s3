<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\AuthController;
use ArcadeCloud\Drive\Http\Request;

(new AuthController(
    ApplicationKernel::app(),
    Request::fromGlobals()
))->logout();
