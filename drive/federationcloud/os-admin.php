<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationOsAdminController;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\FederationOsAdminRenderer;

(new FederationOsAdminController(
    ApplicationKernel::app(),
    Request::fromGlobals(),
    new FederationOsAdminRenderer()
))->index();
