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
$powerEndpoint = (string)file_get_contents($root . '/fastdrive-power.php');
$managed = (string)file_get_contents($root . '/src/Admin/ManagedRuntimeEnvironment.php');
$helper = (string)file_get_contents($root . '/bin/arcadecloud-drive-admin-helper.php');
$gatewayInstaller = (string)file_get_contents($root . '/bin/install_fastdrive_gateway.sh');
$wakeService = (string)file_get_contents($root . '/src/Admin/FastDriveWakeService.php');
$wakeEndpoint = (string)file_get_contents($root . '/fastdrive-wake.php');
$authRepository = (string)file_get_contents($root . '/src/Security/AuthenticationRepository.php');
$identity = (string)file_get_contents($root . '/src/System/Ec2InstanceIdentityService.php');

fastDriveContract(str_contains($service, "getenv('ARCADECLOUD_FASTDRIVE_INSTANCE_ID')"), 'target FastDrive sale de configuración del servidor');
fastDriveContract(str_contains($service, 'Ec2InstanceIdentityService'), 'FastDrive local puede autoidentificarse por IMDSv2');
fastDriveContract(str_contains($service, "['media-worker', 'combined']"), 'fallback IMDS no se activa en gateway web');
fastDriveContract(str_contains($identity, '/meta-data/instance-id'), 'servicio de identidad obtiene Instance ID local');
fastDriveContract(str_contains($identity, 'X-aws-ec2-metadata-token-ttl-seconds'), 'servicio de identidad usa IMDSv2');
fastDriveContract(str_contains($service, 'SuperAdminReauthenticationService'), 'encendido reautentica superadmin');
fastDriveContract(str_contains($service, '->verify($currentPassword)'), 'cada encendido exige contraseña y no reutiliza elevación temporal');
fastDriveContract(str_contains($service, '->start($instanceId)'), 'servicio puede iniciar únicamente el target configurado');
fastDriveContract(str_contains($service, 'public function stop('), 'control dedicado expone apagado manual seguro');
fastDriveContract(str_contains($service, '->stop($instanceId, false)'), 'apagado manual nunca usa Force');
fastDriveContract(str_contains($service, 'ServerTaskActivityProbe'), 'apagado manual comprueba tareas activas');
fastDriveContract(str_contains($service, 'OfficeSessionLeases'), 'apagado manual comprueba sesión Office activa');
fastDriveContract(str_contains($service, 'OfficeDocumentSessions'), 'apagado manual comprueba documentos Office activos');
fastDriveContract(!str_contains($endpoint, "postString('id')"), 'navegador no puede seleccionar un instance-id');
fastDriveContract(str_contains($endpoint, "['start', 'stop']"), 'endpoint limita acciones manuales a start/stop');
fastDriveContract(str_contains($endpoint, '$service->stop($password)'), 'endpoint puede solicitar apagado seguro');
fastDriveContract(str_contains($endpoint, 'Apagar FastDrive'), 'UI ofrece botón de apagado cuando la instancia está running');
fastDriveContract(str_contains($service, 'public function forceStop('), 'servicio expone apagado forzado reautenticado');
fastDriveContract(str_contains($service, '->stop($instanceId, false)'), 'apagado forzado de ArcadeCloud solicita stop normal de AWS');
fastDriveContract(str_contains($service, 'colas y registros de tareas no fueron eliminados'), 'apagado forzado conserva registros de trabajos');
fastDriveContract(str_contains($powerEndpoint, "postString('action') !== 'force-stop'"), 'endpoint rápido sólo acepta force-stop');
fastDriveContract(str_contains($powerEndpoint, 'fastdrive_control_csrf'), 'endpoint rápido exige CSRF dedicado');
fastDriveContract(str_contains($powerEndpoint, '->forceStop('), 'endpoint rápido delega en servicio de control');
fastDriveContract(str_contains($managed, "'ARCADECLOUD_FASTDRIVE_INSTANCE_ID'"), 'runtime administrado permite instance id FastDrive');
fastDriveContract(str_contains($managed, "'ARCADECLOUD_FASTDRIVE_REGION'"), 'runtime administrado permite región FastDrive');
fastDriveContract(str_contains($helper, "'ARCADECLOUD_FASTDRIVE_INSTANCE_ID'"), 'helper privilegiado permite persistir instance id FastDrive');
fastDriveContract(str_contains($helper, "'ARCADECLOUD_FASTDRIVE_REGION'"), 'helper privilegiado permite persistir región FastDrive');
fastDriveContract(str_contains($authRepository, 'findActiveSuperAdmins'), 'repositorio expone únicamente superadmins activos para wake');
fastDriveContract(str_contains($wakeService, 'findActiveSuperAdmins()'), 'wake valida contra superadmins activos');
fastDriveContract(str_contains($wakeService, 'password_verify($password, $hash)'), 'wake verifica hash real del superadmin');
fastDriveContract(str_contains($wakeService, 'MAX_FAILED_ATTEMPTS = 5'), 'wake limita intentos fallidos');
fastDriveContract(str_contains($wakeService, '->start($instanceId)'), 'wake sólo inicia el target configurado');
fastDriveContract(!str_contains($wakeService, '->stop('), 'wake no puede apagar instancias');
fastDriveContract(str_contains($wakeEndpoint, "ARCADECLOUD_FASTDRIVE_GATE"), 'endpoint wake sólo responde detrás del vhost FastDrive');
fastDriveContract(str_contains($wakeEndpoint, 'hash_equals($csrf, $postedCsrf)'), 'endpoint wake exige CSRF');
fastDriveContract(str_contains($wakeEndpoint, 'action="/__fastdrive_start"'), 'formulario de wake permanece en fastdrive.esforzados.com');
fastDriveContract(!str_contains($wakeEndpoint, 'https://drive.esforzados.com'), 'wake no redirige al dominio Drive');
fastDriveContract(str_contains($gatewayInstaller, 'proxy_pass http://${UPSTREAM}'), 'gateway conserva proxy por IPv4 privada');
fastDriveContract(str_contains($gatewayInstaller, 'error_page 502 504 = @fastdrive_wake'), 'fallos del upstream usan fallback interno');
fastDriveContract(str_contains($gatewayInstaller, 'location @fastdrive_wake'), 'fallback se sirve dentro del mismo dominio');
fastDriveContract(str_contains($gatewayInstaller, 'location = /__fastdrive_start'), 'autorización POST se procesa localmente');
fastDriveContract(str_contains($gatewayInstaller, 'ARCADECLOUD_FASTDRIVE_GATE 1'), 'Nginx marca exclusivamente el endpoint interno');
fastDriveContract(!str_contains($gatewayInstaller, 'drive.esforzados.com/fastdrive-control.php'), 'gateway no abandona fastdrive.esforzados.com');
fastDriveContract(!str_contains($gatewayInstaller, 'error_page 502 503 504'), 'un 503 real del FastDrive no se confunde con instancia apagada');
fastDriveContract(str_contains($gatewayInstaller, 'nginx -t'), 'instalador valida Nginx antes de recargar');
fastDriveContract(str_contains($gatewayInstaller, 'BACKUP_DIR='), 'instalador conserva respaldo del vhost anterior');

echo "OK fastdrive_control_contract_smoke\n";
