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
    str_contains($source, '$federationDropPortal = $canonicalHome . \'federationdrop/\''),
    'index público construye FederationDrop desde el portal canónico'
);
indexDropOk(
    str_contains($source, '$federationDropBadge = $canonicalHome . \'federationdrop/badge.svg\''),
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
    str_contains($source, 'id="home"')
        && str_contains($source, 'id="servicios"')
        && str_contains($source, 'id="acerca"')
        && str_contains($source, 'id="contacto"')
        && str_contains($source, 'id="acceso"'),
    'index público es una landing de una sola página con Home, Servicios, Acerca, Contacto y Acceso'
);
indexDropOk(
    str_contains($source, '$canonicalHost = \'drive.esforzados.com\''),
    'contacto se centraliza explícitamente en drive.esforzados.com'
);
indexDropOk(
    str_contains($source, '$contactUrl = $isCanonicalPortal ? \'#contacto\' : $canonicalHome . \'#contacto\''),
    'nodos secundarios envían Contacto al portal principal'
);
indexDropOk(
    str_contains($source, 'form action="psesion.php" method="POST"'),
    'login local permanece dentro de la portada pública'
);
indexDropOk(
    str_contains($source, 'MySQL')
        && str_contains($source, 'Amazon S3')
        && str_contains($source, 'FederationCloud')
        && str_contains($source, 'ArcadeLink')
        && str_contains($source, 'FederationDrop'),
    'landing presenta capacidades reales documentadas del repositorio'
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
