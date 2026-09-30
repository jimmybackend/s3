#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app_bootstrap.php';

$service = new \ArcadeCloud\Drive\Security\UserOsPreferencesSchemaService(
    \ArcadeCloud\Drive\Core\ApplicationKernel::app()->db()
);
$service->ensure();

fwrite(STDOUT, "Users.os_preferences disponible.\n");
