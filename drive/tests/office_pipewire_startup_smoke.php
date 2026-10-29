<?php
declare(strict_types=1);
$root = dirname(__DIR__) . '/docker/workstation';
$config = (string) file_get_contents($root . '/10-arcadecloud-novnc.conf');
$image = (string) file_get_contents($root . '/Dockerfile');
$entry = (string) file_get_contents($root . '/entrypoint.sh');
$audio = (string) file_get_contents($root . '/arcadecloud-audio-server.py');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "ERROR: $message\n");
        exit(1);
    }
    echo "OK: $message\n";
};
$assert(str_contains($config, 'cmd = "load-module"')
    && str_contains($config, 'module-null-sink sink_name=arcadecloud-web'),
    'PipeWire crea sink noVNC desde configuracion de arranque');
$assert(str_contains($image,
    'COPY 10-arcadecloud-novnc.conf /etc/pipewire/pipewire-pulse.conf.d/10-arcadecloud-novnc.conf'),
    'Config PipeWire incluida en la imagen');
$assert(str_contains($entry, 'pgrep -u "$(id -u)" -x pipewire-pulse')
    && str_contains($entry, 'pgrep -u "$(id -u)" -x wireplumber'),
    'Los dos escritorios no duplican los servidores Pulse/WirePlumber');
$assert(str_contains($entry, 'export PULSE_SINK=arcadecloud-web')
    && str_contains($entry, 'ARCADECLOUD_SESSION_TRANSPORT:-'),
    'KDE envia sus aplicaciones al sink dedicado');
$assert(str_contains($audio, 'return "arcadecloud-web.monitor"'),
    'MP3 captura monitor dedicado a KDE');
echo "PipeWire noVNC independiente: pruebas de contrato correctas.\n";
