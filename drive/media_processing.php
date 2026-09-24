<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
$request = \ArcadeCloud\Drive\Http\Request::fromGlobals();

(new \ArcadeCloud\Drive\Http\Controller\MediaProcessingController($app, $request))->dispatch();
