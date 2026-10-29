<?php
declare(strict_types=1);

// Contratos de infraestructura Office sin EC2 ni solicitudes externas.
$root = dirname(__DIR__);
$launch = (string) file_get_contents($root . '/office-launch.php');
$webOs = (string) file_get_contents($root . '/so.php');
$audio = (string) file_get_contents($root . '/docker/workstation/arcadecloud-audio-server.py');
$entry = (string) file_get_contents($root . '/docker/workstation/entrypoint.sh');
$docker = (string) file_get_contents($root . '/docker/workstation/Dockerfile');
$wallpaper = (string) file_get_contents($root . '/docker/workstation/arcadecloud-xfce-wallpaper');
$ignore = (string) file_get_contents($root . '/docker/workstation/.gitignore');

$check = static function (bool $valid, string $message): void {
    if (!$valid) {
        fwrite(STDERR, "ERROR: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
};

$check(str_contains($launch, "\$officeUrl = 'https://office.esforzados.com/';"),
    'Los tokens Office siempre se redirigen al dominio dedicado');
$check(!str_contains($launch, "\$officeUrl = trim((string)(getenv("),
    'Una variable heredada no puede redirigir tokens a FastDrive');
$check(str_contains($webOs, 'href="office-launch.php?target=guacamole"')
    && str_contains($webOs, 'href="office-launch.php?target=kde"'),
    'El menú conserva accesos autenticados con selector');
$check(str_contains($audio, 'MAX_STREAMS = 3'),
    'Audio noVNC admite hasta tres espectadores simultáneos');
$check(str_contains($entry, '/usr/local/bin/arcadecloud-xfce-wallpaper')
    && str_contains($docker, 'COPY arcadecloud-xfce-wallpaper'),
    'El fondo de Guacamole se sincroniza después de arrancar XFCE');
$check(str_contains($wallpaper, 'xfconf-query')
    && str_contains($wallpaper, 'plasma-org.kde.plasma.desktop-appletsrc'),
    'Fondo KDE se reutiliza sin tocar la configuración de Plasma');
$check(str_contains($ignore, '__pycache__/'),
    'Los archivos compilados temporales ya no bloquean el Git updater');
echo "Office gateway/audio/persistence contracts passed.\n";
