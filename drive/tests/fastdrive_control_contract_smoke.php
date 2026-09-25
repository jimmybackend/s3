<?php
declare(strict_types=1);

function fastDriveContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/src/Admin/FastDriveControlService.php');
$endpoint = (string)file_get_contents($root . '/fastdrive-control.php');
$managed = (string)file_get_contents($root . '/src/Admin/ManagedRuntimeEnvironment.php');
$helper = (string)file_get_contents($root . '/bin/arcadecloud-drive-admin-helper.php');

fastDriveContract(str_contains($service, "getenv('ARCADECLOUD_FASTDRIVE_INSTANCE_ID')"), 'target FastDrive sale de configuración del servidor');
fastDriveContract(str_contains($service, 'SuperAdminReauthenticationService'), 'encendido reautentica superadmin');
fastDriveContract(str_contains($service, '->verify($currentPassword)'), 'cada encendido exige contraseña y no reutiliza elevación temporal');
fastDriveContract(str_contains($service, '->start($instanceId)'), 'servicio puede iniciar únicamente el target configurado');
fastDriveContract(!str_contains($service, '->stop('), 'control dedicado no puede apagar instancias');
fastDriveContract(!str_contains($endpoint, "postString('id')"), 'navegador no puede seleccionar un instance-id');
fastDriveContract(str_contains($endpoint, "postString('action') !== 'start'"), 'endpoint rechaza acciones distintas de start');
fastDriveContract(str_contains($managed, "'ARCADECLOUD_FASTDRIVE_INSTANCE_ID'"), 'runtime administrado permite instance id FastDrive');
fastDriveContract(str_contains($managed, "'ARCADECLOUD_FASTDRIVE_REGION'"), 'runtime administrado permite región FastDrive');
fastDriveContract(str_contains($helper, "'ARCADECLOUD_FASTDRIVE_INSTANCE_ID'"), 'helper privilegiado permite persistir instance id FastDrive');
fastDriveContract(str_contains($helper, "'ARCADECLOUD_FASTDRIVE_REGION'"), 'helper privilegiado permite persistir región FastDrive');

echo "OK fastdrive_control_contract_smoke\n";
