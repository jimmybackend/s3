<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$gateway = (string)file_get_contents($root . '/office-gateway.php');
$novnc = (string)file_get_contents($root . '/docker/workstation/arcadecloud-audio.js');
$entry = (string)file_get_contents($root . '/docker/workstation/entrypoint.sh');
$audioServer = (string)file_get_contents($root . '/docker/workstation/arcadecloud-audio-server.py');

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "ERROR: $message\n");
        exit(1);
    }
    echo "OK: $message\n";
};
$check(!str_contains($gateway, 'id="officeAudioToggle"')
    && !str_contains($gateway, 'id="officeAudioTest"')
    && !str_contains($gateway, 'new Audio();'),
    'No hay botones ni segunda captura de audio en el gateway Office');
$check(str_contains($novnc, 'window.__arcadecloudAudioInstalled'),
    'noVNC instala un solo controlador de audio por documento');
$check(str_contains($novnc, "audio.src = '/__office_audio?session='")
    && str_contains($novnc, 'tryStart();'),
    'Audio noVNC intenta iniciar solo cuando navegador lo autoriza');
$check(str_contains($novnc, "error.name === 'NotAllowedError'")
    && str_contains($novnc, "document.addEventListener('pointerdown'")
    && str_contains($novnc, "document.addEventListener('keydown'"),
    'Si autoplay es bloqueado, sonido inicia tras interacción normal');
$check(str_contains($novnc, "prior.style.display = 'none'")
    && str_contains($novnc, "test.style.display = 'none'"),
    'NoVNC oculta ambos botones del gateway público anterior');
$check(!str_contains($novnc, 'createOscillator')
    && !str_contains($novnc, 'document.body.appendChild(button)')
    && !str_contains($novnc, 'document.body.appendChild(test)'),
    'Ya no se emiten tonos de prueba ni controles flotantes');
$check(str_contains($novnc, "window.addEventListener('pagehide'"),
    'Se cierra el flujo cuando termina la sesión noVNC');
$check(str_contains($entry, 'arcadecloud-audio.js')
    && str_contains($audioServer, 'X-Accel-Buffering'),
    'Audio automático mantiene el proxy de streaming protegido');
$check(str_contains($gateway, "allow=\"microphone; autoplay; clipboard-read; clipboard-write\""),
    'El iframe permite autoplay sujeto a política del navegador');
echo "Office audio: un flujo, sin botones flotantes, con gesto de respaldo.\n";
