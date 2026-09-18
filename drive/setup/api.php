<?php
declare(strict_types=1);

$driveRoot = dirname(__DIR__);

require_once $driveRoot . '/src/Admin/ManagedRuntimeEnvironment.php';
require_once $driveRoot . '/src/Admin/PrivilegedServerHelper.php';
require_once $driveRoot . '/src/Setup/BootstrapSetupAuth.php';
require_once $driveRoot . '/src/Setup/SetupConfigurationService.php';
require_once $driveRoot . '/src/Setup/SuperAdminBootstrapService.php';
require_once $driveRoot . '/src/Setup/SetupApiController.php';

(new \ArcadeCloud\Drive\Setup\SetupApiController())->run();
