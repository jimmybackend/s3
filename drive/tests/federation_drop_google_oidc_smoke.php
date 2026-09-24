<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/FederationDropEncryptedCookie.php';
require_once dirname(__DIR__) . '/src/Federation/FederationDropGoogleOidcClient.php';

use ArcadeCloud\Drive\Federation\FederationDropEncryptedCookie;
use ArcadeCloud\Drive\Federation\FederationDropGoogleOidcClient;
use ArcadeCloud\Drive\Federation\FederationException;

function googleDropOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

function googleDropB64u(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function googleDropJsonB64u(array $value): string
{
    return googleDropB64u(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

$now = 1790200000;
$key = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
if ($key === false) throw new RuntimeException('Unable to generate RSA key');
$details = openssl_pkey_get_details($key);
if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
    throw new RuntimeException('Unable to read RSA public details');
}

$issuer = 'https://accounts.google.com';
$clientId = 'synthetic-client.apps.googleusercontent.com';
$clientSecret = 'synthetic-client-secret';
$nonce = 'nonce-federationdrop';
$header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'google-test-key'];
$claims = [
    'iss' => $issuer,
    'sub' => 'google-subject-123',
    'aud' => $clientId,
    'exp' => $now + 600,
    'iat' => $now,
    'nonce' => $nonce,
    'email' => 'owner@example.test',
    'email_verified' => true,
    'name' => 'Owner Test',
    'picture' => 'https://lh3.googleusercontent.com/example',
];
$signingInput = googleDropJsonB64u($header) . '.' . googleDropJsonB64u($claims);
$signature = '';
if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
    throw new RuntimeException('Unable to sign synthetic Google JWT');
}
$idToken = $signingInput . '.' . googleDropB64u($signature);

$requester = function (
    string $method,
    string $url,
    array $headers,
    string $body
) use ($issuer, $clientId, $clientSecret, $idToken, $details): array {
    if ($method === 'GET' && $url === $issuer . '/.well-known/openid-configuration') {
        return [200, json_encode([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/o/oauth2/v2/auth',
            'token_endpoint' => $issuer . '/token',
            'jwks_uri' => $issuer . '/oauth2/v3/certs',
        ], JSON_THROW_ON_ERROR), []];
    }

    if ($method === 'POST' && $url === $issuer . '/token') {
        parse_str($body, $form);
        googleDropOk(($form['grant_type'] ?? null) === 'authorization_code', 'usa authorization_code');
        googleDropOk(($form['client_id'] ?? null) === $clientId, 'envía Google client_id');
        googleDropOk(($form['client_secret'] ?? null) === $clientSecret, 'envía Google client_secret sólo al backend');
        googleDropOk(($form['code'] ?? null) === 'code-123', 'envía authorization code');
        googleDropOk(isset($form['code_verifier']) && strlen((string)$form['code_verifier']) >= 43, 'envía PKCE verifier');
        return [200, json_encode(['id_token' => $idToken], JSON_THROW_ON_ERROR), []];
    }

    if ($method === 'GET' && $url === $issuer . '/oauth2/v3/certs') {
        return [200, json_encode(['keys' => [[
            'kty' => 'RSA',
            'kid' => 'google-test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => googleDropB64u($details['rsa']['n']),
            'e' => googleDropB64u($details['rsa']['e']),
        ]]], JSON_THROW_ON_ERROR), []];
    }

    throw new RuntimeException('Unexpected synthetic Google OIDC request: ' . $method . ' ' . $url);
};

$client = new FederationDropGoogleOidcClient(
    $issuer,
    $clientId,
    $clientSecret,
    'https://drive.esforzados.com/federationdrop/google-callback.php',
    'openid email profile',
    $requester,
    static fn(): int => $now
);

$authUrl = $client->authorizationUrl('state-123', $nonce, str_repeat('A', 43));
googleDropOk(str_starts_with($authUrl, $issuer . '/o/oauth2/v2/auth?'), 'crea URL Google desde discovery');
googleDropOk(str_contains($authUrl, 'code_challenge_method=S256'), 'Authorization URL exige PKCE S256');
googleDropOk(str_contains($authUrl, 'scope=openid%20email%20profile'), 'solicita openid email profile');
googleDropOk(str_contains($authUrl, 'prompt=select_account'), 'permite seleccionar cuenta Google');

$identity = $client->exchangeCode('code-123', str_repeat('v', 43), $nonce);
googleDropOk(($identity['issuer'] ?? null) === $issuer, 'valida issuer Google');
googleDropOk(($identity['subject'] ?? null) === 'google-subject-123', 'valida subject Google');
googleDropOk(($identity['claims']['email'] ?? null) === 'owner@example.test', 'conserva correo Google');
googleDropOk(($identity['claims']['email_verified'] ?? null) === true, 'exige correo Google verificado');

$wrongNonceRejected = false;
try {
    $client->exchangeCode('code-123', str_repeat('v', 43), 'wrong-nonce');
} catch (FederationException) {
    $wrongNonceRejected = true;
}
googleDropOk($wrongNonceRejected, 'rechaza nonce incorrecto');

$cookie = new FederationDropEncryptedCookie(
    'synthetic-google-session-secret-1234567890',
    'google-session'
);
$sealed = $cookie->seal([
    'v' => 1,
    'account_id' => 'fda_' . str_repeat('a', 48),
    'email' => 'owner@example.test',
    'exp' => $now + 300,
]);
$opened = $cookie->open($sealed);
googleDropOk(($opened['email'] ?? null) === 'owner@example.test', 'cookie Google cifra y autentica identidad');

$parts = explode('.', $sealed);
$parts[2] = ($parts[2][0] === 'A' ? 'B' : 'A') . substr($parts[2], 1);
$tamperedRejected = false;
try {
    $cookie->open(implode('.', $parts));
} catch (FederationException) {
    $tamperedRejected = true;
}
googleDropOk($tamperedRejected, 'cookie Google alterada es rechazada');

echo "FederationDrop Google OIDC smoke: OK\n";
