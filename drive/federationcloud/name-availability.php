<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationDirectoryController;
use ArcadeCloud\Drive\Http\Request;

(new FederationDirectoryController(ApplicationKernel::app(), Request::fromGlobals()))->nodeNameAvailabilityApi();
