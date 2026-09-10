<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationController;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\FederationPageRenderer;

$controller = new FederationController(ApplicationKernel::app(), Request::fromGlobals(), new FederationPageRenderer());
$controller->index();
