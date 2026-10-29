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
$check(str_contains($gateway, 'id="officeAudioTest"')
    && str_contains($gateway, 'new AudioCtx()'),
    'Office permite comprobar localmente la salida de audifonos');
$check(str_contains($gateway, "player.controls = true;")
    && str_contains($gateway, "player.addEventListener('playing'")
    && str_contains($gateway, "player.addEventListener('stalled'"),
    'Office expone controles nativos y estado de reproduccion');
$check(str_contains($novnc, 'arcadecloud-audio-test')
    && str_contains($novnc, "audio.controls = true;")
    && str_contains($novnc, "parentToggle.style.display = 'none'"),
    'noVNC conserva diagnostico incluso con gateway publico anterior');

echo "Office gateway audio controls passed.\n";
