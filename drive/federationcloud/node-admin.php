<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationNodeAdminController;
use ArcadeCloud\Drive\Http\Request;

$controller = new FederationNodeAdminController(ApplicationKernel::app(), Request::fromGlobals());
$controller->api();
