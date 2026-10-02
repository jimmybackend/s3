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
$chromeLauncher = (string)file_get_contents($repo . '/drive/docker/workstation/arcadecloud-chrome');
$chromeHelper = (string)file_get_contents($repo . '/drive/docker/workstation/arcadecloud-chrome-helper.desktop');
$install = (string)file_get_contents($repo . '/drive/bin/install_workstation_node.sh');
$uninstall = (string)file_get_contents($repo . '/drive/bin/uninstall_workstation_node.sh');
$health = (string)file_get_contents($repo . '/drive/bin/workstation_health.php');

workstationContract(str_contains($dockerfile, 'FROM ubuntu:24.04'), 'runtime gráfico usa Ubuntu estable aislado del host');
workstationContract(str_contains($dockerfile, 'libreoffice'), 'imagen instala LibreOffice');
workstationContract(!str_contains($entry, "nohup su -s /bin/bash arcade -c 'libreoffice --nologo --norestore'"), 'Workstation no prearranca LibreOffice sin display gráfico');
workstationContract(str_contains($dockerfile, 'xfce4'), 'imagen instala escritorio XFCE');
workstationContract(str_contains($dockerfile, 'google-chrome-stable'), 'imagen instala Google Chrome');
workstationContract(str_contains($dockerfile, 'arcadecloud-chrome-helper.desktop'), 'imagen registra Chrome como helper XFCE');
workstationContract(str_contains($entry, 'WebBrowser=arcadecloud-chrome'), 'XFCE usa Chrome desde el icono de navegador');
workstationContract(str_contains($chromeLauncher, 'SingletonLock'), 'launcher limpia locks obsoletos de Chrome');
workstationContract(str_contains($chromeLauncher, '--disable-dev-shm-usage'), 'launcher aplica ajuste de shared memory');
workstationContract(str_contains($chromeHelper, 'X-XFCE-Category=WebBrowser'), 'helper declara categoría navegador XFCE');
workstationContract(str_contains($dockerfile, 'git'), 'imagen instala Git para repositorios');
workstationContract(str_contains($dockerfile, 'awscli.amazonaws.com'), 'imagen instala AWS CLI v2');
workstationContract(str_contains($dockerfile, 'novnc') && str_contains($dockerfile, 'tigervnc'), 'imagen incluye noVNC y TigerVNC');
workstationContract(str_contains($dockerfile, '--uid 10001') && str_contains($dockerfile, '--gid 10001'), 'usuario Office usa UID/GID aislado y no colisiona con UID 1000');
workstationContract(str_contains($entry, '-localhost yes'), 'VNC sólo acepta conexiones internas del contenedor');
workstationContract(str_contains($entry, '-SecurityTypes None'), 'VNC no solicita una segunda contraseña porque ArcadeCloud protege el acceso web');
workstationContract(str_contains($entry, 'umask 0007'), 'LibreOffice conserva archivos nuevos escribibles por el grupo compartido');
workstationContract(!str_contains($entry, 'VNC_PASSWORD') && !str_contains($entry, 'vncpasswd'), 'entrypoint no administra credenciales VNC');
workstationContract(!str_contains($install, 'VNC_PASSWORD='), 'instalador no genera contraseña VNC');
workstationContract(str_contains($install, '--publish 127.0.0.1:6080:6080'), 'noVNC sólo se publica en loopback del host');
workstationContract(str_contains($install, '--shm-size=512m'), 'escritorio dispone de shared memory explícita');
workstationContract(str_contains($install, '--security-opt=no-new-privileges:true'), 'contenedor impide escalamiento de privilegios');
workstationContract(str_contains($install, '--cap-drop=ALL'), 'contenedor elimina capabilities Linux');
workstationContract(!str_contains($install, '/var/run/docker.sock'), 'Docker socket nunca entra al contenedor');
workstationContract(!str_contains($install, '5900:'), 'puerto VNC no se publica en el host');
workstationContract(!str_contains($install, 'aws_access_key'), 'instalador no incorpora credenciales AWS');
workstationContract(str_contains($install, '/var/lib/arcadecloud-office'), 'workspace queda separado del media worker');
workstationContract(str_contains($install, 'PERSISTENT_HOME='), 'Workstation declara home Linux persistente');
workstationContract(str_contains($install, '--volume $PERSISTENT_HOME:/home/arcade'), 'home del usuario sobrevive reconstrucciones del contenedor');
workstationContract(str_contains($install, '"$PERSISTENT_HOME/Projects"'), 'home persistente prepara carpeta Projects');
workstationContract(str_contains($install, '"$WORKSPACE/sessions"'), 'instalador prepara workspace por sesión documental');
workstationContract(str_contains($install, 'chmod 2770 "$WORKSPACE" "$WORKSPACE/sessions"'), 'workspace usa setgid y no permisos globales');
workstationContract(
    str_contains($install, 'chown "$PHP_USER:$OFFICE_GID" "$WORKSPACE/sessions"')
    && str_contains($install, 'chgrp -R "$OFFICE_GID" "$WORKSPACE/sessions"')
    && str_contains($install, 'find "$WORKSPACE/sessions" -xdev -type d -exec chmod 2770 {} +')
    && str_contains($install, 'find "$WORKSPACE/sessions" -xdev -type f -exec chmod 0660 {} +'),
    'workspace documental usa PHP como propietario y el grupo nativo de arcade'
);
workstationContract(
    !str_contains($install, '--group-add $PHP_GID')
    && !str_contains($entry, 'usermod -aG "$PHP_SHARED_GROUP" arcade'),
    'Workstation no mezcla GID del host con grupos internos del contenedor'
);
workstationContract(!str_contains($install, 'chown root:root /etc/arcadecloud-drive'), 'instalador no rompe el grupo PHP-FPM del directorio administrado');
workstationContract(!str_contains($install, 'chmod 0750 /etc/arcadecloud-drive'), 'instalador no reemplaza permisos del directorio runtime administrado');
workstationContract(!preg_match('/(?:chown|chmod)[^\\n]*runtime-env\\.json/', $install), 'instalador Workstation no cambia permisos de runtime-env.json');
workstationContract(str_contains($install, 'systemctl disable "$SERVICE_NAME"'), 'Workstation no queda habilitada automáticamente al boot');
workstationContract(str_contains($install, '--reconcile'), 'instalador Workstation acepta reconciliación desde updater');
workstationContract(str_contains($install, 'WAS_ACTIVE'), 'reconciliación conoce si el escritorio ya estaba activo');
workstationContract(str_contains($install, 'no se reinicia durante la actualización'), 'updater no corta una sesión Office activa');
workstationContract(str_contains($install, 'permanece apagada hasta que Office la solicite'), 'updater no enciende Workstation si estaba apagada');
workstationContract(!str_contains($install, 'enable --now arcadecloud-workstation'), 'Workstation no se habilita permanentemente por error');
workstationContract(!str_contains($uninstall, 'arcadecloud-media-worker'), 'desinstalador no toca el worker multimedia');
workstationContract(str_contains($health, "fsockopen('127.0.0.1', 6080"), 'health comprueba noVNC local');
fwrite(STDOUT, "Remote workstation phase 1 contract: OK\n");
