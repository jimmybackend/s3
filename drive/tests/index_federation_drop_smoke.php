<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$indexPath = $root . '/index.php';
$source = file_get_contents($indexPath);
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: no se pudo leer drive/index.php\n");
    exit(1);
}

function indexHomeOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

indexHomeOk(
    str_contains($source, '$federationDropUrl = $canonicalHome . \'federationdrop/\'')
        && str_contains($source, 'rawurlencode($sourceDomain)'),
    'FederationDrop conserva portal canónico y atribución del nodo'
);

indexHomeOk(
    str_contains($source, 'css/styles.css?v=')
        && str_contains($source, 'css/responsive.css?v=')
        && str_contains($source, 'class="ui-theme theme-neon-green theme-dark vision-normal ascii-on"')
        && str_contains($source, 'drive-navbar')
        && str_contains($source, 'drive-brand-logo'),
    'portada reutiliza la UI canónica de s3.php'
);

indexHomeOk(
    str_contains($source, 'id="arcadeHomeCarousel"')
        && str_contains($source, 'class="carousel slide public-carousel shadow-sm"')
        && str_contains($source, 'data-interval="6500"'),
    'portada usa carrusel visual Bootstrap'
);

foreach ([
    'images/home/slide-aws.svg',
    'images/home/slide-federation.svg',
    'images/home/slide-drop.svg',
] as $relative) {
    indexHomeOk(
        is_file($root . '/' . $relative),
        'existe visual generado: ' . $relative
    );
    indexHomeOk(
        str_contains($source, $relative),
        'index usa visual generado: ' . $relative
    );
}

indexHomeOk(
    substr_count($source, 'class="carousel-item') === 3,
    'carrusel contiene exactamente tres historias visuales'
);

indexHomeOk(
    str_contains($source, 'Amazon S3 + AWS')
        && str_contains($source, 'FederationCloud')
        && str_contains($source, 'FederationDrop'),
    'carrusel cubre S3/AWS, federación y Drop sin bloques repetidos'
);

indexHomeOk(
    str_contains($source, '$arcadeLinkUrl = \'federationcloud/\'')
        && str_contains($source, '$githubUrl = \'https://github.com/jimmybackend/s3\'')
        && str_contains($source, 'Subir / pagar'),
    'portada conserva accesos directos a funciones reales'
);

indexHomeOk(
    str_contains($source, 'form action="psesion.php" method="POST"'),
    'login local permanece integrado'
);

indexHomeOk(
    str_contains($source, '$authorEmail = \'jimmybackend@gmail.com\'')
        && str_contains($source, '$supportEmail = \'soporte@esforzados.com\'')
        && str_contains($source, '$contactPhoneDisplay = \'+52 9611077442\'')
        && str_contains($source, '$contactPhoneHref = \'+529611077442\''),
    'contacto conserva autor, soporte y teléfono de México'
);

indexHomeOk(
    str_contains($source, '$canonicalHost = \'drive.esforzados.com\'')
        && str_contains($source, '$contactUrl = $isCanonicalPortal ? \'#contacto\' : $canonicalHome . \'#contacto\''),
    'nodos secundarios centralizan Contacto en drive.esforzados.com'
);

indexHomeOk(
    str_contains($source, 'SetupEntryGuard'),
    'portada conserva protección de instalación inicial'
);

indexHomeOk(
    !str_contains($source, 'app_bootstrap.php'),
    'portada pública no arranca la aplicación completa'
);

echo "Public index visual carousel smoke: OK\n";
