<?php
declare(strict_types=1);

// Asegura que KDE y Guacamole jamás se abran en un dominio distinto del
// gateway autorizado sin perder tokens, SSO ni compatibilidad con enlaces.
$root = dirname(__DIR__);
$launch = (string) file_get_contents($root . '/office-launch.php');
$shell = (string) file_get_contents($root . '/js/desktop-shell.js');
$so = (string) file_get_contents($root . '/so.php');

$assert = static function (bool $valid, string $message): void {
    if (!$valid) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
};
$assert(str_contains($so, 'href="office-launch.php?target=kde"')
    && str_contains($so, 'href="office-launch.php?target=guacamole"'),
    'KDE y Guacamole conservan el launcher autenticado en ambas EC2');
$assert(str_contains($launch, "requireAuthenticated('index.php')")
    && str_contains($launch, 'OfficeLaunchTokenRepository')
    && str_contains($launch, 'issue($userId, 120)'),
    'El launcher conserva autenticación y token temporal de un solo uso');
$assert(str_contains($launch, "\$officeUrl = 'https://office.esforzados.com/';")
    && !str_contains($launch, "\$officeUrl = trim((string)(getenv("),
    'El destino final sigue fijado al gateway Office, no a FastDrive');
$assert(str_contains($launch, "(string)(\$_GET['format'] ?? '') === 'json'")
    && str_contains($launch, "'url' => \$destination")
    && str_contains($launch, 'header(\'Content-Type: application/json; charset=utf-8\')')
    && str_contains($launch, "header('Location: ' . \$destination, true, 302)"),
    'La respuesta JSON conserva el redirect 302 original como respaldo');
$assert(str_contains($shell, 'launchOfficeGateway(href, target')
    && str_contains($shell, "card.dataset.launcherApp === 'kde-plasma'")
    && str_contains($shell, "card.dataset.launcherApp === 'guacamole'")
    && str_contains($shell, "event.preventDefault();"),
    'Los clics directos y el registro de aplicaciones usan el mismo acceso seguro');
$assert(str_contains($shell, "credentials: 'same-origin'")
    && str_contains($shell, "redirect: 'error'")
    && str_contains($shell, "destination.hostname !== 'office.esforzados.com'")
    && str_contains($shell, "destination.protocol !== 'https:'")
    && str_contains($shell, "destination.searchParams.has('launch')")
    && str_contains($shell, "destination.searchParams.get('target') !== expectedTarget"),
    'El navegador sólo sigue enlaces temporales válidos hacia Office HTTPS');
$assert(str_contains($shell, 'popup.location.replace(href)')
    && str_contains($shell, 'popup.opener = null'),
    'Las sesiones caducadas conservan la reautenticación; pestaña sin opener');
echo "Office dedicated launcher contract passed.\n";
