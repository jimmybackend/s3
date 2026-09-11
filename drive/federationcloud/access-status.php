<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationAccessController;
use ArcadeCloud\Drive\Http\Request;

(new FederationAccessController(ApplicationKernel::app(), Request::fromGlobals()))->statusApi();
