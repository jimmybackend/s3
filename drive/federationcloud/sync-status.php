<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationCatalogController;
use ArcadeCloud\Drive\Http\Request;

(new FederationCatalogController(ApplicationKernel::app(), Request::fromGlobals()))->syncStatusApi();
