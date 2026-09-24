<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;

final class FederationDropGoogleAuthService
{
    public const STATE_COOKIE = 'arcadecloud_drop_google_state';
    public const SESSION_COOKIE = 'arcadecloud_drop_google_session';

    private FederationDropGoogleAuthConfig $config;
    private FederationDropAccountRepository $accounts;
    private FederationDropEncryptedCookie $stateCipher;
    private FederationDropEncryptedCookie $sessionCipher;
    private FederationDropGoogleOidcClient $oidc;

    public function __construct(private DriveApplication $app)
    {
        $this->config = FederationDropGoogleAuthConfig::fromEnvironment();
        $this->accounts = new FederationDropAccountRepository($this->app->db());

        $secret = $this->config->sessionSecret;
        if ($secret === '') {
            // Sólo permite construir el servicio para mostrar estado "no configurado".
            $secret = str_repeat('x', 32);
        }
        $this->stateCipher = new FederationDropEncryptedCookie($secret, 'google-oidc-state');
        $this->sessionCipher = new FederationDropEncryptedCookie($secret, 'google-session');

        $clientId = $this->config->clientId !== '' ? $this->config->clientId : 'not-configured';
        $clientSecret = $this->config->clientSecret !== '' ? $this->config->clientSecret : 'not-configured';
        $this->oidc = new FederationDropGoogleOidcClient(
            FederationDropGoogleAuthConfig::ISSUER,
            $clientId,
            $clientSecret,
            $this->config->callbackUrl(),
            FederationDropGoogleAuthConfig::SCOPE
        );
    }

    public function pageState(string $sessionCookie, string $sourceDomain = '', string $resourceId = ''): array
    {
        $identity = null;
        if ($this->config->ready() && $sessionCookie !== '') {
            try {
                $identity = $this->sessionIdentity($sessionCookie);
            } catch (FederationException) {
                $identity = null;
            }
        }

        $query = [];
        $sourceDomain = trim($sourceDomain);
        $resourceId = trim($resourceId);
        if ($sourceDomain !== '') $query['source'] = $sourceDomain;
        if ($resourceId !== '') $query['resource_id'] = $resourceId;

        $loginUrl = 'google-login.php';
        if ($query !== []) {
            $loginUrl .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return [
            'enabled' => $this->config->enabled,
            'ready' => $this->config->ready(),
            'provider' => 'google',
            'login_url' => $loginUrl,
            'logout_url' => 'google-logout.php',
            'identity' => $identity,
        ];
    }

    public function beginLogin(string $sourceDomain = '', string $resourceId = ''): array
    {
        $this->config->assertReady();

        $state = self::token(32);
        $nonce = self::token(32);
        $verifier = self::token(48);
        $challenge = self::b64u(hash('sha256', $verifier, true));
        $now = time();

        $return = [];
        $sourceDomain = $this->safeSource($sourceDomain);
        $resourceId = trim($resourceId);
        if ($sourceDomain !== '') $return['source'] = $sourceDomain;
        if ($resourceId !== '' && preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
            $return['resource_id'] = $resourceId;
        }

        $cookie = $this->stateCipher->seal([
            'v' => 1,
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'return' => $return,
            'iat' => $now,
            'exp' => $now + 600,
        ]);

        return [
            'url' => $this->oidc->authorizationUrl($state, $nonce, $challenge),
            'set_cookie' => $this->cookie(self::STATE_COOKIE, $cookie, 600, 'Lax'),
        ];
    }

    public function completeLogin(
        string $authorizationError,
        string $queryState,
        string $code,
        string $stateCookie
    ): array {
        $this->config->assertReady();

        if ($authorizationError !== '') {
            throw new FederationException('Google canceló o rechazó la autorización.', 401);
        }
        if ($stateCookie === '') throw new FederationException('Falta el estado de inicio de sesión Google.', 400);

        $state = $this->stateCipher->open($stateCookie);
        $now = time();
        if (($state['v'] ?? null) !== 1 || !is_int($state['exp'] ?? null) || (int)$state['exp'] < $now) {
            throw new FederationException('El inicio de sesión Google expiró.', 400);
        }
        if ($queryState === '' || !is_string($state['state'] ?? null) || !hash_equals((string)$state['state'], $queryState)) {
            throw new FederationException('Estado Google OIDC inválido.', 400);
        }

        $identity = $this->oidc->exchangeCode(
            $code,
            (string)($state['verifier'] ?? ''),
            (string)($state['nonce'] ?? '')
        );
        $claims = is_array($identity['claims'] ?? null) ? $identity['claims'] : [];

        $email = strtolower(trim((string)($claims['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || ($claims['email_verified'] ?? null) !== true) {
            throw new FederationException('Google no confirmó un correo utilizable.', 401);
        }

        $name = $this->safeText((string)($claims['name'] ?? ''), 256);
        $picture = trim((string)($claims['picture'] ?? ''));
        if ($picture !== '' && !$this->safeHttpsUrl($picture)) $picture = '';

        $account = $this->accounts->upsertGoogle(
            (string)$identity['issuer'],
            (string)$identity['subject'],
            $email,
            $name,
            $picture
        );

        $expiresAt = min((int)$identity['expires_at'], $now + $this->config->sessionTtl);
        $session = $this->sessionCipher->seal([
            'v' => 1,
            'provider' => 'google',
            'account_id' => (string)$account['account_id'],
            'iss' => (string)$identity['issuer'],
            'sub' => (string)$identity['subject'],
            'email' => $email,
            'email_verified' => true,
            'name' => $name,
            'picture' => $picture,
            'iat' => $now,
            'exp' => $expiresAt,
        ]);

        $return = is_array($state['return'] ?? null) ? $state['return'] : [];
        $query = ['google_login' => '1'];
        foreach (['source', 'resource_id'] as $key) {
            $value = $return[$key] ?? null;
            if (is_string($value) && $value !== '') $query[$key] = $value;
        }

        return [
            'redirect_url' => $this->config->publicUrl . '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'set_cookies' => [
                $this->cookie(self::SESSION_COOKIE, $session, max(1, $expiresAt - $now), 'Strict'),
                $this->clearCookie(self::STATE_COOKIE),
            ],
            'identity' => $account,
        ];
    }

    public function sessionIdentity(string $sessionCookie): array
    {
        $this->config->assertReady();
        if ($sessionCookie === '') throw new FederationException('Sesión Google FederationDrop ausente.', 401);

        $session = $this->sessionCipher->open($sessionCookie);
        if (($session['v'] ?? null) !== 1 || ($session['provider'] ?? null) !== 'google') {
            throw new FederationException('Sesión Google FederationDrop inválida.', 401);
        }
        $exp = $session['exp'] ?? null;
        if (!is_int($exp) || $exp < time()) throw new FederationException('Sesión Google FederationDrop expirada.', 401);

        $accountId = (string)($session['account_id'] ?? '');
        $issuer = (string)($session['iss'] ?? '');
        $subject = (string)($session['sub'] ?? '');
        if (!$this->accounts->identityMatches($accountId, $issuer, $subject)) {
            throw new FederationException('La identidad Google FederationDrop ya no es válida.', 401);
        }

        $account = $this->accounts->findActive($accountId);
        if ($account === null) throw new FederationException('La cuenta FederationDrop está deshabilitada.', 401);

        return $account + [
            'provider' => 'google',
            'email_verified' => true,
        ];
    }

    public function clearSessionCookie(): string
    {
        return $this->clearCookie(self::SESSION_COOKIE);
    }

    private function cookie(string $name, string $value, int $maxAge, string $sameSite): string
    {
        return $name . '=' . rawurlencode($value)
            . '; Path=' . $this->config->cookiePath()
            . '; Max-Age=' . $maxAge
            . '; Secure; HttpOnly; SameSite=' . $sameSite;
    }

    private function clearCookie(string $name): string
    {
        return $name . '=; Path=' . $this->config->cookiePath()
            . '; Max-Age=0; Secure; HttpOnly; SameSite=Lax';
    }

    private function safeSource(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === '') return '';
        if (filter_var($source, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) return '';
        return substr($source, 0, 255);
    }

    private function safeText(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value);
        if (function_exists('mb_substr')) return mb_substr($value, 0, $max);
        return substr($value, 0, $max);
    }

    private function safeHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    private static function token(int $bytes): string
    {
        return self::b64u(random_bytes($bytes));
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
