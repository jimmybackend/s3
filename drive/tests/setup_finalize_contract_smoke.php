<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$helper = (string)file_get_contents($root . '/bin/arcadecloud-drive-admin-helper.php');
$helperInstaller = (string)file_get_contents($root . '/bin/install_arcadecloud_admin_helper.sh');
$installer = (string)file_get_contents($root . '/bin/install_arcadecloud.sh');
$privileged = (string)file_get_contents($root . '/src/Admin/PrivilegedServerHelper.php');
$superadmin = (string)file_get_contents($root . '/src/Setup/SuperAdminBootstrapService.php');
$nodeAdmin = (string)file_get_contents($root . '/src/Federation/FederationNodeAdminService.php');

function checkFinalize(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

checkFinalize(str_contains($helperInstaller, '"app_root": app_root'), 'helper installer persists fixed app_root');
checkFinalize(str_contains($helper, "'setup_finalize' => true"), 'helper advertises setup_finalize capability');
checkFinalize(str_contains($helper, "\$action === 'bootstrap-finalize'"), 'helper exposes fixed bootstrap-finalize action');
checkFinalize(str_contains($helper, "'--finalize-from-setup'"), 'helper invokes guarded installer mode');
checkFinalize(str_contains($helper, 'completeBootstrap($bootstrapAuthPath, $setupLockPath)'), 'helper closes bootstrap only inside privileged flow');
checkFinalize(str_contains($privileged, 'finalizeBootstrapInstallation'), 'PHP facade exposes automatic finalization');
checkFinalize(str_contains($superadmin, '$this->finalizeInstallation();'), 'superadmin flow requests finalization');
checkFinalize(!str_contains($superadmin, '$this->helper->completeBootstrapSetup();'), 'superadmin flow does not close setup early');
checkFinalize(str_contains($installer, '--finalize-from-setup'), 'installer accepts internal finalize-from-setup mode');
checkFinalize(str_contains($installer, 'bootstrap-auth.json'), 'installer requires active bootstrap for web finalization');
checkFinalize(str_contains($installer, 'SUDO_USER'), 'installer binds internal mode to PHP-FPM sudo caller');
checkFinalize(str_contains($installer, '--require-directory'), 'finalization requires global directory confirmation');
checkFinalize(str_contains($nodeAdmin, "'ready' => false"), 'identity admin can expose pending identity state');

fwrite(STDOUT, "setup finalize contract smoke: OK\n");
