<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationEndpointResolver.php';

use ArcadeCloud\Drive\Federation\FederationEndpointResolver;
use ArcadeCloud\Drive\Federation\FederationException;

function endpointOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$resolver = new FederationEndpointResolver();

$domain = $resolver->resolve(
    'https://drive.example.test',
    'https://drive.example.test/federationcloud/',
    '203.0.113.20'
);
endpointOk($domain['mode'] === 'domain', 'dominio configurado tiene prioridad sobre IP detectada');
endpointOk($domain['host'] === 'drive.example.test', 'conserva hostname DNS');
endpointOk($domain['public_url'] === 'https://drive.example.test', 'construye public_url HTTPS');
endpointOk($domain['federation_url'] === 'https://drive.example.test/federationcloud/', 'construye federation_url HTTPS');

$ip = $resolver->resolve('', '', '8.8.8.8');
endpointOk($ip['mode'] === 'dynamic_ip', 'sin dominio usa IPv4 pública detectada');
endpointOk($ip['host'] === '8.8.8.8', 'publica IPv4 detectada');
endpointOk($ip['public_url'] === 'https://8.8.8.8', 'genera public_url por IP');
endpointOk($ip['federation_url'] === 'https://8.8.8.8/federationcloud/', 'genera federation_url por IP');

$changed = $resolver->resolve(
    'https://1.1.1.1',
    'https://1.1.1.1/federationcloud/',
    '8.8.4.4'
);
endpointOk($changed['mode'] === 'dynamic_ip', 'una URL IP sigue en modo dynamic_ip');
endpointOk($changed['host'] === '8.8.4.4', 'IP detectada reemplaza IP anterior');
endpointOk($changed['ip_changed'] === true, 'detecta cambio de IPv4 pública');

$fallback = $resolver->resolve(
    'https://8.8.8.8',
    'https://8.8.8.8/federationcloud/',
    null
);
endpointOk($fallback['host'] === '8.8.8.8', 'usa IP configurada como fallback si IMDS no responde');
endpointOk($fallback['ip_changed'] === false, 'fallback no inventa cambio de IP');

$rejectedPrivate = false;
try {
    $resolver->resolve('', '', '10.0.0.25');
} catch (FederationException) {
    $rejectedPrivate = true;
}
endpointOk($rejectedPrivate, 'rechaza IPv4 privada como endpoint público');

$rejectedBrokenUrl = false;
try {
    $resolver->resolve('not-a-url', '', '8.8.8.8');
} catch (FederationException) {
    $rejectedBrokenUrl = true;
}
endpointOk($rejectedBrokenUrl, 'rechaza URL configurada inválida en vez de adivinar');

$rejectedCredentials = false;
try {
    $resolver->resolve('https://user:pass@drive.example.test', '', '8.8.8.8');
} catch (FederationException) {
    $rejectedCredentials = true;
}
endpointOk($rejectedCredentials, 'rechaza credenciales embebidas en URL configurada');

$rejectedQuery = false;
try {
    $resolver->resolve('https://drive.example.test/?token=secret', '', '8.8.8.8');
} catch (FederationException) {
    $rejectedQuery = true;
}
endpointOk($rejectedQuery, 'rechaza query en URL configurada');

$rejectedScheme = false;
try {
    $resolver->resolve('ftp://drive.example.test', '', '8.8.8.8');
} catch (FederationException) {
    $rejectedScheme = true;
}
endpointOk($rejectedScheme, 'rechaza esquema distinto de HTTP/HTTPS');

fwrite(STDOUT, "Federation endpoint resolver smoke: OK\n");
