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
        && str_contains($source, 'class="carousel slide home-carousel"')
        && str_contains($source, 'data-interval="6500"'),
    'portada usa carrusel visual principal'
);

$slides = [
    'images/home/carousel-01-s3-aws.png',
    'images/home/carousel-02-s3-aws-alt.png',
    'images/home/carousel-03-s3-servicios.png',
    'images/home/carousel-04-federationcloud.png',
    'images/home/carousel-05-federationdrop.png',
];

foreach ($slides as $relative) {
    indexHomeOk(
        is_file($root . '/' . $relative),
        'existe PNG del carrusel: ' . $relative
    );
    indexHomeOk(
        str_contains($source, $relative),
        'index usa PNG del carrusel: ' . $relative
    );
}

indexHomeOk(
    substr_count($source, 'class="carousel-item') === 5,
    'carrusel contiene exactamente cinco imágenes'
);

indexHomeOk(
    str_contains($source, '<div class="carousel-item active">')
        && str_contains($source, 'images/home/carousel-01-s3-aws.png'),
    'bucket S3 con nube AWS queda como imagen principal'
);

indexHomeOk(
    !str_contains($source, 'carousel-indicators')
        && !str_contains($source, 'data-slide-to='),
    'carrusel no muestra puntos indicadores'
);

indexHomeOk(
    str_contains($source, 'object-fit: contain;')
        && str_contains($source, 'height: auto;')
        && str_contains($source, 'max-height: 42vh;'),
    'imágenes se reducen en móvil sin recortarse'
);

indexHomeOk(
    str_contains($source, 'home-link-card')
        && str_contains($source, 'Acceso al nodo')
        && str_contains($source, 'ArcadeLink')
        && str_contains($source, 'FederationDrop')
        && str_contains($source, 'GitHub'),
    'portada mantiene cuatro accesos directos visuales'
);

indexHomeOk(
    str_contains($source, 'id="loginModal"')
        && str_contains($source, 'data-target="#loginModal"')
        && str_contains($source, 'form action="psesion.php" method="POST"'),
    'login local se integra en modal'
);

indexHomeOk(
    str_contains($source, '$authorEmail = \'jimmybackend@gmail.com\'')
        && str_contains($source, '$supportEmail = \'soporte@esforzados.com\'')
        && str_contains($source, '$contactPhoneDisplay = \'+52 9611077442\'')
        && str_contains($source, '$contactPhoneHref = \'+529611077442\''),
    'contacto conserva autor, soporte y teléfono de México'
);

indexHomeOk(
    str_contains($source, 'SetupEntryGuard'),
    'portada conserva protección de instalación inicial'
);

indexHomeOk(
    !str_contains($source, 'app_bootstrap.php'),
    'portada pública no arranca la aplicación completa'
);

echo "Public index approved PNG carousel smoke: OK\n";
