<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Office/OfficeUserContainerIdentity.php';

use ArcadeCloud\Drive\Office\OfficeUserContainerIdentity;

$a = new OfficeUserContainerIdentity(1);
$b = new OfficeUserContainerIdentity(23);

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "ERROR: $message\n");
        exit(1);
    }
    echo "OK: $message\n";
};

$check($a->containerName() === 'arcadecloud-office-u1', 'ID de contenedor determinista');
$check($b->containerName() !== $a->containerName(), 'Contenedores distintos para usuarios diferentes');
$check($a->homeDirectory() === '/var/lib/arcadecloud-office/users/u1/home',
    'Home exclusivamente dentro del directorio del usuario');
$check($a->homeDirectory() !== $b->homeDirectory(), 'Perfiles de Chrome/KDE aislados por usuario');
$check($a->workspaceDirectory() !== $b->workspaceDirectory(), 'Workspaces separados por usuario');
$check($a->routingKey() !== $b->routingKey(), 'Rutas lógicas independientes');
$check(str_starts_with($a->directory(), '/var/lib/arcadecloud-office/users/'), 'Rutas controladas');
try {
    new OfficeUserContainerIdentity(0);
    $check(false, 'Se aceptó usuario no autenticado');
} catch (InvalidArgumentException $e) {
    $check(true, 'Se rechaza usuario no autenticado');
}
$gateway = (string)file_get_contents(dirname(__DIR__) . '/office-gateway.php');
$launcher = (string)file_get_contents(dirname(__DIR__) . '/office-launch.php');
$check(str_contains($launcher, '$session->requireAuthenticated(') &&
       str_contains($launcher, '$repo->issue($userId, 120)'),
       'Sigue presente el lanzamiento SSO con token temporal');
$check(str_contains($gateway, '$office->assertOfficeSession('),
       'La pasarela sigue validando la sesión en servidor');
echo "Esqueleto multiusuario listo; no activa en producción los contenedores por usuario.\n";
