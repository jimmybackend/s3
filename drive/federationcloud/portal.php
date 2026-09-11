<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationPortalController;
use ArcadeCloud\Drive\View\FederationPortalRenderer;

(new FederationPortalController(ApplicationKernel::app(), new FederationPortalRenderer()))->index();
