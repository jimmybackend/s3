<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationProviderController;
use ArcadeCloud\Drive\Http\Request;

(new FederationProviderController(ApplicationKernel::app(), Request::fromGlobals()))->providersApi();
