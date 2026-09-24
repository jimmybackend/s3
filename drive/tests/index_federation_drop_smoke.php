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
    str_contains($source, '$_SERVER[\'HTTP_HOST\']')
        && str_contains($source, 'rawurlencode($sourceDomain)'),
    'index atribuye y codifica el dominio del nodo para FederationDrop'
);
indexDropOk(
    str_contains($source, 'id="home"')
        && str_contains($source, 'id="archivos-aws"')
        && str_contains($source, 'id="formas-de-uso"')
        && str_contains($source, 'id="login"')
        && str_contains($source, 'id="acerca"')
        && str_contains($source, 'id="contacto"'),
    'index público mantiene una sola página con producto, flujos, login, acerca y contacto'
);
indexDropOk(
    str_contains($source, 'Amazon Textract')
        && str_contains($source, 'Amazon Transcribe')
        && str_contains($source, 'Amazon Polly')
        && str_contains($source, 'Amazon Translate')
        && str_contains($source, 'Amazon Rekognition')
        && str_contains($source, 'Amazon Comprehend'),
    'portada presenta las acciones AWS reales disponibles desde los archivos'
);
indexDropOk(
    str_contains($source, 'Usuario registrado del Drive')
        && str_contains($source, 'Usuario de ArcadeLink')
        && str_contains($source, 'Sólo transferir un archivo'),
    'portada diferencia claramente tres formas de uso'
);
indexDropOk(
    str_contains($source, 'La búsqueda global entre nodos requiere iniciar sesión.'),
    'búsqueda federada queda presentada como función exclusiva de usuarios registrados'
);
indexDropOk(
    str_contains($source, '$arcadeLinkUrl = \'federationcloud/\'')
        && str_contains($source, 'Abrir ArcadeLink'),
    'visitante puede ir al lector ArcadeLink sin convertirlo en búsqueda global'
);
indexDropOk(
    str_contains($source, 'FederationDrop sin cuenta del Drive')
        && str_contains($source, 'No necesita cuenta del Drive.'),
    'FederationDrop se presenta como transferencia temporal sin cuenta del Drive'
);
indexDropOk(
    str_contains($source, 'form action="psesion.php" method="POST"'),
    'login local permanece integrado directamente en la portada'
);
indexDropOk(
    str_contains($source, '$canonicalHost = \'drive.esforzados.com\'')
        && str_contains($source, '$contactUrl = $isCanonicalPortal ? \'#contacto\' : $canonicalHome . \'#contacto\''),
    'nodos secundarios centralizan Contacto en drive.esforzados.com'
);
indexDropOk(
    str_contains($source, '$contactEmail = \'soporte@esforzados.com\'')
        && str_contains($source, '$contactPhone = \'9611077442\''),
    'acerca/contacto muestran correo y teléfono del proyecto'
);
indexDropOk(
    str_contains($source, 'SetupEntryGuard'),
    'la portada conserva la protección de instalación inicial'
);
indexDropOk(
    !str_contains($source, 'app_bootstrap.php'),
    'la portada pública no arranca la aplicación completa'
);

echo "Public index product smoke: OK\n";
