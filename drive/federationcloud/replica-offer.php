<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Controller\FederationReplicaController;
use ArcadeCloud\Drive\Http\Request;

(new FederationReplicaController(ApplicationKernel::app(), Request::fromGlobals()))->offerApi();
