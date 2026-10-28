<?php
declare(strict_types=1);

// Contratos de integración: KDE no abre Guacamole, Chrome usa el wrapper.
$root = dirname(__DIR__);
$gateway = file_get_contents($root . '/office-gateway.php');
$launcher = file_get_contents($root . '/office-launch.php');
$os = file_get_contents($root . '/so.php');
$docker = file_get_contents($root . '/docker/workstation/Dockerfile');
$entrypoint = file_get_contents($root . '/docker/workstation/entrypoint.sh');
$repair = file_get_contents($root . '/docker/workstation/repair-chrome-launchers.py');

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: $message\n");
};

$check(str_contains($gateway, '$officeDesktopTarget === \'guacamole\' ? \'/guacamole/\''),
    'Sólo Guacamole RDP usa la ruta /guacamole/');
$check(!str_contains($gateway, "in_array(\$officeDesktopTarget, ['guacamole', 'kde'], true) ? '/guacamole/'"),
    'KDE ya no se dirige a Guacamole');
$check(str_contains($launcher, "if (\$target === 'kde')"),
    'Launch KDE normalizado a noVNC antes de redireccionar al gateway');
$check(str_contains($os, 'data-launcher-app="kde-plasma"') &&
    str_contains($os, 'href="office-launch.php?target=kde"'),
    'ArcadeCloud OS muestra el acceso KDE Plasma directo');
$check(str_contains($docker, 'COPY repair-chrome-launchers.py'),
    'Imagen Docker incluye la reparación de launchers');
$check(str_contains($entrypoint, 'python3 /usr/local/bin/repair-chrome-launchers.py'),
    'Reparación se aplica antes de arrancar el escritorio');
$check(str_contains($repair, '/usr/share/applications/google-chrome.desktop') &&
    str_contains($repair, 'HOME / "Desktop" / "Chrome.desktop"'),
    'Se reparan tanto Chrome del Dock como su acceso en el escritorio');
$check(str_contains($repair, 'arcadecloud-chrome'),
    'Todos los lanzadores apuntan al wrapper Chrome de ArcadeCloud');
echo "Office KDE direct & Chrome launcher contract passed.\n";
