<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);

function officeGatewayContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$so = (string)file_get_contents($repo . '/drive/so.php');
$launch = (string)file_get_contents($repo . '/drive/office-launch.php');
$tokens = (string)file_get_contents($repo . '/drive/src/Office/OfficeLaunchTokenRepository.php');
$gateway = (string)file_get_contents($repo . '/drive/office-gateway.php');
$control = (string)file_get_contents($repo . '/drive/workstation-control.php');
$helperClient = (string)file_get_contents($repo . '/drive/src/Admin/PrivilegedServerHelper.php');
$helper = (string)file_get_contents($repo . '/drive/bin/arcadecloud-drive-admin-helper.php');
$officeInstaller = (string)file_get_contents($repo . '/drive/bin/install_office_gateway.sh');
$internalInstaller = (string)file_get_contents($repo . '/drive/bin/install_workstation_internal_gateway.sh');
$node = (string)file_get_contents($repo . '/drive/src/Media/MediaWorkerNodeService.php');

officeGatewayContract(
    str_contains($so, 'href="office-launch.php"')
    && str_contains($so, 'target="_blank"')
    && str_contains($so, '<strong>Office</strong><span>Disponible</span>'),
    'ArcadeCloud OS abre Office disponible en una pestaña nueva'
);

officeGatewayContract(
    str_contains($launch, "requireAuthenticated('index.php')")
    && str_contains($launch, 'OfficeLaunchTokenRepository')
    && str_contains($launch, 'launch='),
    'launcher exige sesión Drive y emite token temporal'
);

officeGatewayContract(
    str_contains($tokens, 'random_bytes(32)')
    && str_contains($tokens, "hash('sha256', \$token)")
    && str_contains($tokens, 'ConsumedAt IS NULL')
    && str_contains($tokens, 'LIMIT 1 FOR UPDATE'),
    'token Office es aleatorio, hash-only y de un solo uso'
);

officeGatewayContract(
    str_contains($gateway, 'FastDriveWakeService')
    && str_contains($gateway, 'authorizeAndStart')
    && str_contains($gateway, 'current_password'),
    'gateway Office reutiliza autorización superadmin para encender la EC2'
);

officeGatewayContract(
    str_contains($gateway, 'MediaWorkerNodeService')
    && str_contains($gateway, 'touchInteractiveActivity')
    && str_contains($gateway, 'handleIdle')
    && str_contains($gateway, 'MediaProcessingJobRepository'),
    'Office reutiliza actividad e inactividad segura del nodo de cómputo'
);

officeGatewayContract(
    str_contains($gateway, "['pointerdown','pointermove','keydown','touchstart','wheel']")
    && str_contains($gateway, '/__office_activity')
    && str_contains($gateway, '/__office_idle')
    && str_contains($gateway, 'Han pasado 10 minutos sin actividad'),
    'pestaña Office detecta interacción real y muestra aviso de inactividad'
);

officeGatewayContract(
    str_contains($gateway, 'hasActiveJobs()')
    && str_contains($gateway, 'Hay una tarea multimedia activa'),
    'Office espera si FFmpeg/multimedia ya está trabajando'
);

officeGatewayContract(
    str_contains($control, "ARCADECLOUD_WORKSTATION_GATE")
    && str_contains($control, "['status', 'start']")
    && !str_contains($control, "['status', 'start', 'stop']"),
    'endpoint interno sólo permite estado e inicio de Workstation'
);

officeGatewayContract(
    str_contains($helperClient, 'supportsWorkstationControl')
    && str_contains($helperClient, "workstationControl('workstation-start')"),
    'cliente del helper expone arranque Workstation allowlisted'
);

officeGatewayContract(
    str_contains($helper, "'version' => 13")
    && str_contains($helper, "'workstation_control' => true")
    && str_contains($helper, "'workstation-start'")
    && !str_contains($helper, "'workstation-stop'"),
    'helper privilegiado no expone parada Workstation directa'
);

officeGatewayContract(
    str_contains($officeInstaller, 'UPSTREAM="172.31.14.35"')
    && str_contains($officeInstaller, 'office.esforzados.com')
    && str_contains($officeInstaller, 'error_page 502 504 = @office_gate')
    && str_contains($officeInstaller, '/__office_activity'),
    'gateway público usa EC2 pequeña y upstream privado con fallback de encendido'
);

officeGatewayContract(
    str_contains($internalInstaller, 'GATEWAY_IP="172.31.83.240"')
    && str_contains($internalInstaller, 'allow ${GATEWAY_IP};')
    && str_contains($internalInstaller, 'proxy_pass http://127.0.0.1:6080')
    && str_contains($internalInstaller, '/__arcadecloud_workstation'),
    'gateway grande sólo acepta control plane privado y conserva noVNC en loopback'
);

officeGatewayContract(
    str_contains($node, 'max(')
    && str_contains($node, '600')
    && str_contains($node, 'ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS')
    && str_contains($node, 'hasActiveJobs()'),
    'autoapagado existente conserva mínimo de 10 minutos y protege trabajos multimedia'
);

fwrite(STDOUT, "Office gateway contract: OK\n");
