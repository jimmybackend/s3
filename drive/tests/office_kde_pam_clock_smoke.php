<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$gateway = (string)file_get_contents($root . '/office-gateway.php');
$novnc = (string)file_get_contents($root . '/docker/workstation/arcadecloud-audio.js');
$docker = (string)file_get_contents($root . '/docker/workstation/Dockerfile');
$pam = (string)file_get_contents($root . '/docker/workstation/pam-kde');
$installer = (string)file_get_contents($root . '/bin/install_workstation_node.sh');

$check = static function (bool $ok, string $desc): void {
    if (!$ok) { fwrite(STDERR, "ERROR: $desc\n"); exit(1); }
    echo "OK: $desc\n";
};
$check(str_contains($gateway, '.office-idle-status{position:fixed;z-index:40;right:12px;bottom:12px;'),
    'Reloj de inactividad ubicado a 12px de ambos bordes');
$check(str_contains($gateway, '@media(max-width:600px){.office-idle-status{right:12px;bottom:12px;'),
    'Reloj ajustado también en pantallas pequeñas');
$check(str_contains($novnc, "idleCountdown.style.right = '12px'")
    && str_contains($novnc, "idleCountdown.style.bottom = '12px'"),
    'noVNC aplica temporalmente el reloj correcto con gateway antiguo');
$check(str_contains($docker, 'COPY pam-kde /etc/pam.d/kde')
    && str_contains($pam, '@include common-auth'),
    'Servicio PAM KDE incluido en la imagen');
$check(!str_contains($pam, 'pam_permit') && !str_contains($pam, 'pam_exec'),
    'El archivo PAM no omite autenticación');
$check(str_contains($installer, '--security-opt=no-new-privileges:true'),
    'La protección no-new-privileges permanece habilitada');
echo "Ubicación de contador y configuración PAM: contrato aprobado.\n";
