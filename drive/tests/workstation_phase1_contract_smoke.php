<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
function workstationContract(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}
$dockerfile = (string)file_get_contents($repo . '/drive/docker/workstation/Dockerfile');
$entry = (string)file_get_contents($repo . '/drive/docker/workstation/entrypoint.sh');
$install = (string)file_get_contents($repo . '/drive/bin/install_workstation_node.sh');
$uninstall = (string)file_get_contents($repo . '/drive/bin/uninstall_workstation_node.sh');
$health = (string)file_get_contents($repo . '/drive/bin/workstation_health.php');

workstationContract(str_contains($dockerfile, 'FROM ubuntu:24.04'), 'runtime gráfico usa Ubuntu estable aislado del host');
workstationContract(str_contains($dockerfile, 'libreoffice'), 'imagen instala LibreOffice');
workstationContract(str_contains($dockerfile, 'xfce4'), 'imagen instala escritorio XFCE');
workstationContract(str_contains($dockerfile, 'novnc') && str_contains($dockerfile, 'tigervnc'), 'imagen incluye noVNC y TigerVNC');
workstationContract(str_contains($entry, '-localhost yes'), 'VNC sólo acepta conexiones internas del contenedor');
workstationContract(str_contains($install, '--publish 127.0.0.1:6080:6080'), 'noVNC sólo se publica en loopback del host');
workstationContract(str_contains($install, '--security-opt=no-new-privileges:true'), 'contenedor impide escalamiento de privilegios');
workstationContract(str_contains($install, '--cap-drop=ALL'), 'contenedor elimina capabilities Linux');
workstationContract(!str_contains($install, '/var/run/docker.sock'), 'Docker socket nunca entra al contenedor');
workstationContract(!str_contains($install, '5900:'), 'puerto VNC no se publica en el host');
workstationContract(!str_contains($install, 'aws_access_key'), 'instalador no incorpora credenciales AWS');
workstationContract(str_contains($install, '/var/lib/arcadecloud-office'), 'workspace queda separado del media worker');
workstationContract(!str_contains($uninstall, 'arcadecloud-media-worker'), 'desinstalador no toca el worker multimedia');
workstationContract(str_contains($health, "fsockopen('127.0.0.1', 6080"), 'health comprueba noVNC local');
fwrite(STDOUT, "Remote workstation phase 1 contract: OK\n");
