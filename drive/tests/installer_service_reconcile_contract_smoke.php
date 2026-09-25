<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);

function installerContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$installer = (string)file_get_contents($repo . '/drive/bin/install_arcadecloud.sh');
$reconciler = (string)file_get_contents($repo . '/drive/bin/reconcile_arcadecloud_services.sh');
$uninstaller = (string)file_get_contents($repo . '/drive/bin/uninstall_arcadecloud.sh');
$updater = (string)file_get_contents($repo . '/drive/bin/arcadecloud-drive-updater.php');
$updaterInstaller = (string)file_get_contents($repo . '/drive/bin/install_arcadecloud_updater.sh');
$updaterService = (string)file_get_contents($repo . '/drive/src/Admin/ArcadeCloudUpdaterService.php');
$mediaInstaller = (string)file_get_contents($repo . '/drive/bin/install_media_processing_worker.sh');
$bootstrap = (string)file_get_contents($repo . '/drive/bin/media_worker_node_bootstrap.sh');
$managed = (string)file_get_contents($repo . '/drive/src/Admin/ManagedRuntimeEnvironment.php');
$helper = (string)file_get_contents($repo . '/drive/bin/arcadecloud-drive-admin-helper.php');

installerContract(str_contains($installer, '--reconcile'), 'instalador ofrece reconciliación idempotente');
installerContract(str_contains($installer, '--node-role='), 'instalador distingue rol web/media-worker/combined');
installerContract(str_contains($installer, '--media-worker-instance-id='), 'instalador puede guardar la EC2 multimedia controlada');
installerContract(str_contains($installer, 'persist_node_settings'), 'instalador persiste rol y configuración de media');
installerContract(str_contains($installer, 'reconcile_services'), 'finalización invoca reconciliación de servicios');

$installerPoolPos = strpos($installer, 'pool_user_from_conf /etc/php-fpm-drive.d/arcadecloud-drive.conf');
$installerPsPos = strpos($installer, 'ps -eo user=,comm=');
installerContract(
    $installerPoolPos !== false && $installerPsPos !== false && $installerPoolPos < $installerPsPos,
    'detección prioriza el pool php-fpm-drive antes de procesos genéricos'
);

$reconcilerPoolPos = strpos($reconciler, 'pool_user_from_conf /etc/php-fpm-drive.d/arcadecloud-drive.conf');
$reconcilerPsPos = strpos($reconciler, 'ps -eo user=,comm=');
installerContract(
    $reconcilerPoolPos !== false && $reconcilerPsPos !== false && $reconcilerPoolPos < $reconcilerPsPos,
    'reconciliador prioriza el pool php-fpm-drive'
);

installerContract(str_contains($installer, 'systemctl show -p MainPID --value php-fpm-drive.service'), 'instalador puede localizar config desde el master php-fpm-drive');
installerContract(str_contains($installer, '/proc/$pid/cmdline'), 'instalador inspecciona argumentos reales del master');
installerContract(str_contains($reconciler, 'systemctl show -p MainPID --value php-fpm-drive.service'), 'reconciliador soporta instalaciones legacy sin pool en ruta nueva');
installerContract(str_contains($reconciler, 'expanded_pool_user'), 'reconciliador expande la configuración efectiva de PHP-FPM');
installerContract(str_contains($installer, 'target_listen="${2:-127.0.0.1:9075}"'), 'instalador selecciona el pool que escucha en 9075');
installerContract(str_contains($reconciler, 'target_listen="${2:-127.0.0.1:9075}"'), 'reconciliador selecciona el pool que escucha en 9075');

installerContract(str_contains($reconciler, 'ROLE="web"'), 'rol seguro por defecto es web');
installerContract(str_contains($reconciler, 'install_media_processing_worker.sh'), 'rol multimedia instala worker');
installerContract(str_contains($reconciler, 'install_transcribe_reconcile_timer.sh'), 'rol web instala reconciliación Transcribe');
installerContract(str_contains($reconciler, 'install_polly_reconcile_timer.sh'), 'rol web instala reconciliación Polly');
installerContract(str_contains($reconciler, 'systemctl restart php-fpm-drive.service'), 'reconciliación reinicia PHP-FPM administrado');

installerContract(str_contains($updater, 'reconcileServices'), 'updater web reconcilia servicios tras fast-forward');
installerContract(!str_contains($updater, "'--php-user=' . (string)\$config['php_user']"), 'updater no impone php_user obsoleto al reconciliador');
installerContract(str_contains($reconciler, 'DETECTED_PHP_USER="$(detect_drive_php_user || true)"'), 'reconciliador redetecta el usuario real de Drive en cada ejecución');
installerContract(str_contains($reconciler, 'no coincide con el pool real de Drive'), 'reconciliador rechaza un php_user explícito incorrecto');
installerContract(str_contains($helperInstaller = (string)file_get_contents($repo . '/drive/bin/install_arcadecloud_admin_helper.sh'), 'runuser -u "$PHP_USER" -- test -r "$RUNTIME_ENV_PATH"'), 'helper verifica lectura del runtime como el usuario PHP');
installerContract(str_contains($helperInstaller, 'runuser -u "$PHP_USER" -- test -r "$IDENTITY_PATH"'), 'helper verifica lectura de identidad como el usuario PHP');
installerContract(str_contains($updater, "'needs_attention'"), 'updater informa si código quedó actualizado pero servicios requieren atención');
installerContract(str_contains($updaterInstaller, '"php_user": php_user'), 'configuración del updater conserva usuario PHP-FPM');
installerContract(str_contains($updaterInstaller, 'NOPASSWD: %s probe, %s check, %s apply'), 'sudoers limita updater a probe/check/apply');
installerContract(str_contains($updaterInstaller, 'runuser -u "$PHP_USER" -- /usr/bin/sudo -n "$TARGET" probe'), 'instalador prueba NOPASSWD con el usuario web real');
installerContract(str_contains($updater, "if (" . '$' . "action === 'probe')"), 'updater ofrece probe sin tocar Git');
installerContract(str_contains($updaterService, 'perdió la autorización NOPASSWD'), 'UI traduce fallo sudo a diagnóstico ArcadeCloud');

installerContract(str_contains($uninstaller, 'arcadecloud-media-worker.service'), 'desinstalador retira worker multimedia');
installerContract(str_contains($uninstaller, 'arcadecloud-media-node-bootstrap.service'), 'desinstalador retira bootstrap multimedia');
installerContract(str_contains($uninstaller, '/var/lib/arcadecloud-media'), 'desinstalador retira temporales locales multimedia');

installerContract(str_contains($mediaInstaller, 'ARCADECLOUD_RUNTIME_ENV'), 'worker usa runtime administrado canónico');
installerContract(!str_contains($mediaInstaller, 'User=nginx'), 'worker no fija nginx como usuario universal');
installerContract(str_contains($bootstrap, 'runtime-env.json'), 'bootstrap de réplica usa runtime-env.json');
installerContract(str_contains($bootstrap, 'arcadecloud-drive-admin'), 'bootstrap actualiza configuración mediante helper privilegiado');

foreach ([
    'ARCADECLOUD_NODE_ROLE',
    'ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID',
    'ARCADECLOUD_MEDIA_WORKER_REGION',
    'ARCADECLOUD_MEDIA_WORKER_HOURLY_USD',
    'ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS',
    'ARCADECLOUD_FEDERATION_DYNAMIC_IP',
] as $name) {
    installerContract(str_contains($managed, "'{$name}'"), "runtime administrado permite {$name}");
    installerContract(str_contains($helper, "'{$name}'"), "helper privilegiado permite {$name}");
}

fwrite(STDOUT, "Installer/service reconciliation contract: OK\n");
