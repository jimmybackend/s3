<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$entry = (string) file_get_contents($root . '/docker/workstation/entrypoint.sh');
$boot = (string) file_get_contents($root . '/docker/workstation/arcadecloud-audio-bootstrap');
$docker = (string) file_get_contents($root . '/docker/workstation/Dockerfile');
$audio = (string) file_get_contents($root . '/docker/workstation/arcadecloud-audio-server.py');
$script = (string) file_get_contents($root . '/bin/office_cold_start_health.sh');
$installer = (string) file_get_contents($root . '/bin/install_workstation_node.sh');

$check = static function(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "ERROR: $message\n"); exit(1); }
    echo "OK: $message\n";
};
$audioInit = strpos($entry, '===== PREPARANDO AUDIO WEB SIN GUACAMOLE =====');
$vncInit = strpos($entry, '===== ARRANCANDO VNC =====');
$check($audioInit !== false && $vncInit !== false && $audioInit < $vncInit,
    'PipeWire se inicializa antes de TigerVNC');
$check(str_contains($entry, '-xstartup /home/arcade/.vnc/xstartup'),
    'TigerVNC ignora los archivos xstartup persistentes obsoletos y usa ruta explícita');
$check(str_contains($entry, 'pgrep -u arcade -x plasmashell'),
    'El arranque espera a KDE en lugar de sólo abrir el puerto VNC');
$check(str_contains($docker, 'COPY arcadecloud-audio-bootstrap /usr/local/bin/arcadecloud-audio-bootstrap')
    && str_contains($docker, 'chmod 0755')
    && str_contains($docker, '/usr/local/bin/arcadecloud-audio-bootstrap /usr/local/bin'),
    'Audio bootstrap existe y es ejecutable en la imagen');
$check(str_contains($boot, 'nohup pipewire') && str_contains($boot, 'nohup pipewire-pulse')
    && str_contains($boot, 'nohup wireplumber')
    && str_contains($boot, 'arcadecloud-audio-ensure-sink'),
    'PipeWire y el sink se crean sin XRDP/Guacamole');
$check(!str_contains($boot, 'set-default-sink')
    && str_contains($audio, '"arcadecloud-web.monitor"'),
    'Se conserva el audio XRDP y el monitor exclusivo de noVNC');
$check(str_contains($script, 'arcade-audio-bootstrap.log')
    && !str_contains($script, 'rdp.env'),
    'Diagnóstico sin mostrar contraseñas');
$check(str_contains($installer, '--security-opt=no-new-privileges:true')
    && str_contains($installer, '--publish 127.0.0.1:6080:6080')
    && str_contains($installer, '--publish 127.0.0.1:6081:6081'),
    'Mantiene autenticación del gateway y restricciones Docker');
echo "Office cold start: contrato de arranque independiente correcto.\n";
