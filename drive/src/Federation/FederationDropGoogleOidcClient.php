<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use JsonException;
use RuntimeException;

final class FederationDropGoogleOidcClient
{
    /** @var null|callable */
    private $requester;
    /** @var callable */
    private $clock;
    private ?array $discoveryCache = null;

    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $scope = 'openid email profile',
        ?callable $requester = null,
        ?callable $clock = null
    ) {
        self::assertHttpsUrl($this->issuer, 'Google OIDC issuer');
        if ($this->clientId === '' || strlen($this->clientId) > 512) {
            throw new RuntimeException('Google OIDC client id is required');
        }
        if ($this->clientSecret === '' || strlen($this->clientSecret) > 4096) {
            throw new RuntimeException('Google OIDC client secret is required');
        }
        self::assertHttpsUrl($this->redirectUri, 'Google OIDC redirect URI');
        if (!preg_match('/^openid(?: [A-Za-z0-9._:-]+)*$/', trim($this->scope))) {
            throw new RuntimeException('Google OIDC scope must include openid and safe scope tokens');
        }

        $this->requester = $requester;
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        foreach ([$state, $nonce, $codeChallenge] as $value) {
            if ($value === '' || strlen($value) > 512) {
                throw new RuntimeException('Invalid Google OIDC authorization parameter');
            }
        }

        $discovery = $this->discovery();
        return $discovery['authorization_endpoint'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier, string $expectedNonce): array
    {
        if ($code === '' || strlen($code) > 4096) {
            throw new FederationException('Código de autorización Google inválido.', 400);
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $codeVerifier)) {
            throw new FederationException('PKCE Google inválido.', 400);
        }
        if ($expectedNonce === '' || strlen($expectedNonce) > 512) {
            throw new FederationException('Nonce Google inválido.', 400);
        }

        $discovery = $this->discovery();
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $codeVerifier,
        ];

        [$status, $body] = $this->request(
            'POST',
            $discovery['token_endpoint'],
            ['content-type' => 'application/x-www-form-urlencoded', 'accept' => 'application/json'],
            http_build_query($form, '', '&', PHP_QUERY_RFC3986)
        );
        if ($status !== 200) {
            throw new FederationException('Google no pudo completar el intercambio OIDC.', 401);
        }

        $tokens = self::jsonObject($body, 'Google token response');
        $idToken = $tokens['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new FederationException('Google no devolvió un ID token.', 401);
        }

        $claims = $this->validateIdToken($idToken, $expectedNonce, (string)$discovery['jwks_uri']);
        return [
            'issuer' => (string)$claims['iss'],
            'subject' => (string)$claims['sub'],
            'expires_at' => (int)$claims['exp'],
            'claims' => $claims,
        ];
    }

    public function validateIdToken(string $jwt, string $expectedNonce, string $jwksUri): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new FederationException('ID token Google mal formado.', 401);
        }

        $header = self::jsonObject(self::b64uDecode($parts[0]), 'Google JWT header');
        $claims = self::jsonObject(self::b64uDecode($parts[1]), 'Google JWT claims');
        $signature = self::b64uDecode($parts[2]);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new FederationException('Google ID token debe usar RS256.', 401);
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '' || strlen($kid) > 512) {
            throw new FederationException('Google ID token no contiene kid válido.', 401);
        }

        self::assertHttpsUrl($jwksUri, 'Google OIDC JWKS URI');
        [$status, $jwksBody] = $this->request('GET', $jwksUri, ['accept' => 'application/json']);
        if ($status !== 200) {
            throw new FederationException('No se pudieron cargar las claves públicas de Google.', 503);
        }
        $jwks = self::jsonObject($jwksBody, 'Google JWKS response');

        $key = null;
        foreach (($jwks['keys'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            if (($candidate['kid'] ?? null) !== $kid) continue;
            if (($candidate['kty'] ?? null) !== 'RSA') continue;
            if (isset($candidate['use']) && $candidate['use'] !== 'sig') continue;
            if (isset($candidate['alg']) && $candidate['alg'] !== 'RS256') continue;
            $key = $candidate;
            break;
        }
        if ($key === null) {
            throw new FederationException('La clave pública Google del token no fue encontrada.', 401);
        }

        $publicKey = openssl_pkey_get_public(self::jwkToPem($key));
        if ($publicKey === false) {
            throw new FederationException('La clave pública Google no es válida.', 401);
        }

        $verified = openssl_verify(
            $parts[0] . '.' . $parts[1],
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );
        if ($verified !== 1) {
            throw new FederationException('Firma Google ID token inválida.', 401);
        }

        $now = (int)($this->clock)();
        $skew = 60;

        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new FederationException('Issuer Google no coincide con la configuración.', 401);
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (!in_array($this->clientId, $audiences, true)) {
            throw new FederationException('Audience Google no contiene este cliente.', 401);
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId) {
            throw new FederationException('Authorized party Google inválido.', 401);
        }
        if (isset($claims['azp']) && $claims['azp'] !== $this->clientId) {
            throw new FederationException('Authorized party Google no coincide con este cliente.', 401);
        }

        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) || $exp < $now - $skew) {
            throw new FederationException('Google ID token expirado.', 401);
        }
        if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + $skew)) {
            throw new FederationException('Google ID token todavía no es válido.', 401);
        }
        if (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] > $now + $skew)) {
            throw new FederationException('Google ID token contiene iat inválido.', 401);
        }
        if (($claims['nonce'] ?? null) !== $expectedNonce) {
            throw new FederationException('Nonce Google no coincide.', 401);
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || trim($sub) === '' || strlen($sub) > 255) {
            throw new FederationException('Subject Google inválido.', 401);
        }

        $email = $claims['email'] ?? null;
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320) {
            throw new FederationException('Google no devolvió un correo válido.', 401);
        }
        if (($claims['email_verified'] ?? null) !== true) {
            throw new FederationException('Google no confirmó el correo de esta cuenta.', 401);
        }

        return $claims;
    }

    private function discovery(): array
    {
        if ($this->discoveryCache !== null) return $this->discoveryCache;

        $url = rtrim($this->issuer, '/') . '/.well-known/openid-configuration';
        [$status, $body] = $this->request('GET', $url, ['accept' => 'application/json']);
        if ($status !== 200) {
            throw new FederationException('Google OIDC discovery no está disponible.', 503);
        }

        $discovery = self::jsonObject($body, 'Google OIDC discovery');
        if (($discovery['issuer'] ?? null) !== $this->issuer) {
            throw new FederationException('Google OIDC discovery devolvió issuer inesperado.', 503);
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $name) {
            if (!is_string($discovery[$name] ?? null)) {
                throw new FederationException('Google OIDC discovery incompleto.', 503);
            }
            self::assertHttpsUrl((string)$discovery[$name], 'Google OIDC ' . $name);
        }

        return $this->discoveryCache = $discovery;
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        if ($this->requester !== null) {
            $result = ($this->requester)(strtoupper($method), $url, $headers, $body);
            if (!is_array($result) || count($result) < 2) {
                throw new RuntimeException('Invalid Google OIDC requester result');
            }
            return [
                (int)$result[0],
                (string)$result[1],
                is_array($result[2] ?? null) ? $result[2] : [],
            ];
        }

        if (!function_exists('curl_init')) {
            throw new FederationException('FederationDrop Google requiere PHP cURL.', 503);
        }

        $wire = [];
        foreach ($headers as $name => $value) $wire[] = $name . ': ' . $value;

        $ch = curl_init($url);
        if ($ch === false) throw new FederationException('No se pudo iniciar conexión Google OIDC.', 503);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $wire,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (strtoupper($method) === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new FederationException('Error HTTP hacia Google OIDC: ' . $error, 503);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string)$response, []];
    }

    private static function jwkToPem(array $jwk): string
    {
        $n = self::b64uDecode((string)($jwk['n'] ?? ''));
        $e = self::b64uDecode((string)($jwk['e'] ?? ''));
        if (strlen(ltrim($n, "\0")) < 256) {
            throw new FederationException('Google RSA signing key debe ser de al menos 2048 bits.', 401);
        }
        if ($e === '') throw new FederationException('Google RSA exponent ausente.', 401);

        $rsa = self::derSequence(self::derInteger($n) . self::derInteger($e));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if ($algorithm === false) throw new RuntimeException('Unable to build RSA algorithm identifier');
        $spki = self::derSequence($algorithm . self::derBitString($rsa));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '') $bytes = "\0";
        if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\0" . $bytes;
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $bytes): string
    {
        return "\x30" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derBitString(string $bytes): string
    {
        $bytes = "\0" . $bytes;
        return "\x03" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) return chr($length);
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function jsonObject(string $body, string $label): array
    {
        try {
            $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException($label . ' no contiene JSON válido.', 401);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new FederationException($label . ' debe ser un objeto JSON.', 401);
        }
        return $value;
    }

    private static function b64uDecode(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new FederationException('Google JWT contiene base64url inválido.', 401);
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new FederationException('Google JWT contiene base64url inválido.', 401);
        }
        return $decoded;
    }

    private static function assertHttpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new RuntimeException($label . ' must be an absolute HTTPS URL');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException($label . ' must not include credentials or fragment');
        }
    }
}
