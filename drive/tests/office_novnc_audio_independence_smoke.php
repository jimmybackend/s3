<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$entry = (string)file_get_contents($root . '/docker/workstation/entrypoint.sh');
$helper = (string)file_get_contents($root . '/docker/workstation/arcadecloud-novnc-audio-init');
$dockerfile = (string)file_get_contents($root . '/docker/workstation/Dockerfile');
$audio = (string)file_get_contents($root . '/docker/workstation/arcadecloud-audio-server.py');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "ERROR: $message\n");
        exit(1);
    }
    echo "OK: $message\n";
};
$assert(str_contains($entry, 'export ARCADECLOUD_SESSION_TRANSPORT=novnc') &&
        str_contains($entry, 'export ARCADECLOUD_SESSION_TRANSPORT=xrdp'),
        'noVNC y XRDP etiquetados independientemente');
$assert(str_contains($entry, 'export PULSE_SINK=arcadecloud-novnc-sink') &&
        str_contains($entry, 'if [ "${ARCADECLOUD_SESSION_TRANSPORT:-}" = "novnc" ]'),
        'Sólo aplicaciones noVNC dirigen audio al sink virtual');
$assert(!str_contains($helper, 'pactl set-default-sink'),
        'No se altera el sink predeterminado global de Guacamole');
$assert(str_contains($helper, 'module-null-sink') &&
        str_contains($dockerfile, 'COPY arcadecloud-novnc-audio-init'),
        'El sink virtual está en la imagen y se crea por sesión');
$assert(str_contains($audio, 'return "arcadecloud-novnc-sink.monitor"'),
        'El canal MP3 captura sólo el monitor noVNC si está disponible');
echo "Independencia de audio noVNC/XRDP: contrato aprobado.\n";
