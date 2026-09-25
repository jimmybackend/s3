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
checkFinalize(str_contains($helper, 'assertActiveSuperadminExists'), 'helper verifies active superadmin before finalization');
checkFinalize(str_contains($helper, "\$action === 'bootstrap-finalize'"), 'helper exposes fixed bootstrap-finalize action');
checkFinalize(str_contains($helper, "'--finalize-from-setup'"), 'helper invokes guarded installer mode');
checkFinalize(str_contains($helper, 'completeBootstrap($bootstrapAuthPath, $setupLockPath)'), 'helper closes bootstrap only inside privileged flow');
checkFinalize(str_contains($privileged, 'finalizeBootstrapInstallation'), 'PHP facade exposes automatic finalization');
checkFinalize(str_contains($superadmin, '$this->finalizeInstallation();'), 'superadmin flow requests finalization');
checkFinalize(!str_contains($superadmin, '$this->helper->completeBootstrapSetup();'), 'superadmin flow does not close setup early');
checkFinalize(str_contains($installer, '--finalize-from-setup'), 'installer accepts internal finalize-from-setup mode');
checkFinalize(str_contains($installer, 'bootstrap-auth.json'), 'installer requires active bootstrap for web finalization');
checkFinalize(str_contains($installer, 'SUDO_USER'), 'installer binds internal mode to PHP-FPM sudo caller');
checkFinalize(str_contains($installer, 'federation_catalog_migrate.php'), 'installer contains the FederationCloud migrator helper');
checkFinalize(str_contains($installer, '--require-directory'), 'finalization requires global directory confirmation');
checkFinalize(str_contains($installer, '--tls-termination='), 'installer accepts an explicit TLS termination mode');
checkFinalize(str_contains($installer, '--public-url='), 'installer accepts a canonical public URL for gateway deployments');
checkFinalize(str_contains($installer, 'verify_gateway_https'), 'gateway finalization verifies the externally terminated HTTPS endpoint');
checkFinalize(str_contains($installer, 'TLS público administrado por gateway; Certbot local omitido.'), 'gateway mode skips local Certbot explicitly');
checkFinalize(str_contains($installer, 'ARCADECLOUD_TLS_TERMINATION'), 'gateway/local TLS mode persists in managed runtime');

$finalizeStart = strpos($installer, "finalize_installation() {");
$finalizeEnd = $finalizeStart === false
    ? false
    : strpos($installer, "\necho \"ArcadeCloud Drive Installer\"", $finalizeStart);
checkFinalize(
    $finalizeStart !== false && $finalizeEnd !== false && $finalizeEnd > $finalizeStart,
    'finalize_installation body can be isolated for ordering checks'
);

$finalizeBody = substr($installer, $finalizeStart, $finalizeEnd - $finalizeStart);
checkFinalize(
    substr_count($finalizeBody, 'migrate_federation_schema') === 1,
    'finalize_installation calls the single Federation schema migration helper exactly once'
);
checkFinalize(
    !str_contains($finalizeBody, 'federation_catalog_migrate.php'),
    'finalize_installation does not duplicate the migrator inline'
);

$migratePos = strpos($finalizeBody, 'migrate_federation_schema');
$httpsStartPos = strpos($finalizeBody, 'systemctl start arcadecloud-federation-https.service');
$refreshPos = strpos($finalizeBody, 'federation_endpoint_refresh.php');
checkFinalize(
    $migratePos !== false
        && $httpsStartPos !== false
        && $refreshPos !== false
        && $migratePos < $httpsStartPos
        && $httpsStartPos < $refreshPos,
    'finalize_installation orders schema migration before HTTPS and endpoint refresh'
);
checkFinalize(str_contains($nodeAdmin, "'ready' => false"), 'identity admin can expose pending identity state');

fwrite(STDOUT, "setup finalize contract smoke: OK\n");
