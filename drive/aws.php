<?php
declare(strict_types=1);

require_once __DIR__ . '/personal_aws_bootstrap.php';

$runtime = new \ArcadeCloud\Drive\Aws\PersonalAwsRuntime();

(new \ArcadeCloud\Drive\Http\Controller\PersonalAwsController(
    $runtime,
    \ArcadeCloud\Drive\Http\Request::fromGlobals()
))->handle();
