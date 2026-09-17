<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\RepositoryUpdateController;
use ArcadeCloud\Drive\Http\Request;

(new RepositoryUpdateController(ApplicationKernel::app(), Request::fromGlobals()))->api();
