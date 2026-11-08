<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app_bootstrap.php';
(new \ArcadeCloud\Drive\Http\Controller\LegalDriveController(\ArcadeCloud\Drive\Core\ApplicationKernel::app()))->dispatch(true);
