<?php
declare(strict_types=1);

$indexPath = dirname(__DIR__) . '/index.php';
$source = file_get_contents($indexPath);
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: no se pudo leer drive/index.php\n");
    exit(1);
}

function indexDropOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

indexDropOk(
    str_contains($source, "https://drive.esforzados.com/federationdrop/"),
    'index público apunta al portal canónico FederationDrop'
);
indexDropOk(
    str_contains($source, "https://drive.esforzados.com/federationdrop/badge.svg"),
    'index público usa el badge oficial FederationDrop'
);
indexDropOk(
    str_contains($source, '$_SERVER[\'HTTP_HOST\']'),
    'index atribuye el dominio del nodo visitante'
);
indexDropOk(
    str_contains($source, 'rawurlencode($sourceDomain)'),
    'source del nodo se codifica antes de entrar en la URL'
);
indexDropOk(
    str_contains($source, '¿No tienes cuenta en este nodo?'),
    'visitante recibe una ruta explícita sin cuenta del Drive'
);
indexDropOk(
    str_contains($source, 'SetupEntryGuard'),
    'el acceso FederationDrop no elimina la protección de instalación inicial'
);
indexDropOk(
    !str_contains($source, "app_bootstrap.php"),
    'index público no arranca la aplicación completa sólo para mostrar FederationDrop'
);

echo "Public index FederationDrop smoke: OK\n";
