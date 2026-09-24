<?php
declare(strict_types=1);

$indexPath = dirname(__DIR__) . '/index.php';
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
    str_contains($source, 'bootstrap/4.5.2')
        && str_contains($source, 'font-awesome/6.0.0'),
    'portada mantiene las mismas dependencias visuales base de s3.php'
);

indexHomeOk(
    str_contains($source, 'id="inicio"')
        && str_contains($source, 'id="aws"')
        && str_contains($source, 'id="accesos"')
        && str_contains($source, 'id="login"')
        && str_contains($source, 'id="acerca"')
        && str_contains($source, 'id="contacto"'),
    'portada mantiene navegación pública compacta'
);

indexHomeOk(
    str_contains($source, 'Textract')
        && str_contains($source, 'Transcribe')
        && str_contains($source, 'Polly')
        && str_contains($source, 'Translate')
        && str_contains($source, 'Rekognition')
        && str_contains($source, 'Comprehend'),
    'servicios AWS reales reciben protagonismo propio'
);

indexHomeOk(
    str_contains($source, 'Una de las capacidades que distingue a ArcadeCloud.')
        && str_contains($source, 'Los archivos almacenados en S3 pueden utilizar directamente servicios administrados de AWS desde el Drive.'),
    'AWS se presenta como capacidad distintiva integrada al Drive'
);

indexHomeOk(
    str_contains($source, '$arcadeLinkUrl = \'federationcloud/\'')
        && str_contains($source, '$githubUrl = \'https://github.com/jimmybackend/s3\'')
        && str_contains($source, 'Subir / pagar'),
    'portada ofrece accesos directos a funciones reales'
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

indexHomeOk(
    !str_contains($source, 'js/estilo.js'),
    'portada no carga módulos privados del Drive sólo para copiar el aspecto visual'
);

echo "Public index native Drive UI smoke: OK\n";
