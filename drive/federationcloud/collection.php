<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationCollectionController;
use ArcadeCloud\Drive\Http\Request;

$controller = new FederationCollectionController(ApplicationKernel::app(), Request::fromGlobals());
$controller->download();
