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
    str_contains($source, 'id="inicio"')
        && str_contains($source, 'id="servicios"')
        && str_contains($source, 'id="enlaces"')
        && str_contains($source, 'id="login"')
        && str_contains($source, 'id="acerca"')
        && str_contains($source, 'id="contacto"'),
    'portada conserva navegación simple de una sola página'
);

indexHomeOk(
    str_contains($source, 'Drive sobre Amazon S3')
        && str_contains($source, 'Servicios AWS')
        && str_contains($source, 'FederationCloud'),
    'portada resume el producto en tres capacidades sin repetir flujos'
);

indexHomeOk(
    str_contains($source, '$arcadeLinkUrl = \'federationcloud/\'')
        && str_contains($source, '$githubUrl = \'https://github.com/jimmybackend/s3\'')
        && str_contains($source, 'Subir / pagar'),
    'portada ofrece navegación directa a páginas funcionales'
);

indexHomeOk(
    str_contains($source, 'Textract, Transcribe, Polly, Translate, Rekognition y Comprehend'),
    'servicios AWS reales se presentan una sola vez de forma compacta'
);

indexHomeOk(
    str_contains($source, 'form action="psesion.php" method="POST"'),
    'login local permanece integrado'
);

indexHomeOk(
    str_contains($source, '$contactEmail = \'soporte@esforzados.com\'')
        && str_contains($source, '$contactPhoneDisplay = \'+52 9611077442\'')
        && str_contains($source, '$contactPhoneHref = \'+529611077442\''),
    'contacto usa correo central y teléfono mexicano completo'
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

echo "Public index navigation smoke: OK\n";
