<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$gateway = (string)file_get_contents($root . '/office-gateway.php');
$novnc = (string)file_get_contents($root . '/docker/workstation/arcadecloud-audio.js');

$check = static function (bool $ok, string $msg): void {
    if (!$ok) {
        fwrite(STDERR, "ERROR: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
};
$check(str_contains($gateway, "officeAudioToggle"), 'Office gateway ofrece un control de audio');
$check(str_contains($gateway, "\$officeDesktopTarget !== 'guacamole'"),
    'El control MP3 no duplica el audio propio de Guacamole');
$check(str_contains($gateway, "player.src = '/__office_audio?session='"),
    'Audio Office utiliza el mismo endpoint protegido');
$check(str_contains($gateway, "officeAudioToggle.addEventListener('click'"),
    'Reproducción requiere interacción del usuario');
$check(str_contains($gateway, "window.addEventListener('pagehide', stopAudio"),
    'La reproducción termina al cerrar Office');
$check(str_contains($novnc, "window.parent.document.getElementById('officeAudioToggle')"),
    'noVNC evita doble audio cuando el gateway tiene botón');
echo "Office gateway audio controls passed.\n";
