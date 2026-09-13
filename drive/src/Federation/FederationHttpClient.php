<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use JsonException;

final class FederationHttpClient
{
    private const DEFAULT_MAX_RESPONSE_BYTES = 65536;
    private const SYNC_MAX_RESPONSE_BYTES = 262144;
    private const ALLOWED_ENDPOINTS = [
        'node.php',
        'resolve.php',
        'register.php',
        'nodes.php',
        'name-availability.php',
        'provider-request.php',
        'provider-presence.php',
        'providers.php',
        'sync-pull.php',
        'sync-push.php',
        'access-request.php',
        'access-status.php',
        'replica-offer.php',
        'replica-resolve.php',
    ];

    public function getJson(string $federationUrl, string $endpoint): array
    {
        return $this->requestJson($federationUrl, $endpoint, 'GET', null);
    }

    public function postJson(string $federationUrl, string $endpoint, array $body): array
    {
        return $this->requestJson($federationUrl, $endpoint, 'POST', $body);
    }

    private function requestJson(string $baseUrl, string $endpoint, string $method, ?array $body): array
    {
        if (!extension_loaded('curl')) {
            throw new FederationException('La extensión curl de PHP es necesaria para consultar nodos remotos.', 503);
        }
        if (!in_array($endpoint, self::ALLOWED_ENDPOINTS, true)) {
            throw new FederationException('Endpoint federado remoto no permitido.');
        }
        [$url, $host, $ip] = $this->safeTarget($baseUrl, $endpoint);
        $maxResponseBytes = $endpoint === 'sync-pull.php' ? self::SYNC_MAX_RESPONSE_BYTES : self::DEFAULT_MAX_RESPONSE_BYTES;
        $response = '';
        $ch = curl_init($url);
        if ($ch === false) throw new FederationException('No se pudo inicializar cURL.', 500);

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ArcadeCloud-Federation/2'],
            CURLOPT_RESOLVE => [$host . ':443:' . $ip],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, $maxResponseBytes): int {
                if (strlen($response) + strlen($chunk) > $maxResponseBytes) return 0;
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($method === 'POST') {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $maxRequestBytes = $endpoint === 'sync-push.php' ? self::SYNC_MAX_RESPONSE_BYTES : ArcadeLinkService::MAX_BYTES;
            if (strlen($json) > $maxRequestBytes) {
                curl_close($ch);
                throw new FederationException('Solicitud federada demasiado grande.');
            }
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $json;
            $options[CURLOPT_HTTPHEADER] = [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: ArcadeCloud-Federation/2',
            ];
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $error = curl_error($ch);
        curl_close($ch);
        if ($ok === false || $http < 200 || $http >= 300) {
            throw new FederationException('El nodo FederationCloud no respondió correctamente' . ($error !== '' ? ': ' . $error : '.'), 502);
        }
        if ($contentType !== '' && !str_starts_with($contentType, 'application/json')) {
            throw new FederationException('El nodo remoto devolvió un tipo de contenido inesperado.', 502);
        }
        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('El nodo remoto devolvió JSON inválido.', 502);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new FederationException('Respuesta federada remota inválida.', 502);
        }
        return $decoded;
    }

    private function safeTarget(string $baseUrl, string $endpoint): array
    {
        $parts = parse_url(trim($baseUrl));
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])) {
            throw new FederationException('Los nodos federados remotos deben usar HTTPS.', 400);
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new FederationException('URL federada remota no permitida.', 400);
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($port !== 443) throw new FederationException('Sólo se permite HTTPS en puerto 443 para nodos remotos.', 400);
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($host === '' || strlen($host) > 253 || !preg_match('/\A[a-z0-9.-]+\z/', $host)) {
            throw new FederationException('Host federado remoto inválido.', 400);
        }
        $ip = $this->resolvePublicIpv4($host);
        $path = rtrim((string)($parts['path'] ?? '/'), '/') . '/';
        if (str_contains($path, '..')) throw new FederationException('Ruta federada remota inválida.', 400);
        $url = 'https://' . $host . $path . $endpoint;
        return [$url, $host, $ip];
    }

    private function resolvePublicIpv4(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!$this->isPublicIp($host)) throw new FederationException('El nodo remoto apunta a una IP privada o reservada.', 400);
            return $host;
        }
        $records = @dns_get_record($host, DNS_A);
        if (!is_array($records) || $records === []) {
            throw new FederationException('No se pudo resolver el host federado remoto.', 502);
        }
        $public = [];
        foreach ($records as $record) {
            $ip = (string)($record['ip'] ?? '');
            if ($ip === '') continue;
            if (!$this->isPublicIp($ip)) {
                throw new FederationException('DNS del nodo remoto contiene una IP privada o reservada.', 400);
            }
            $public[] = $ip;
        }
        if ($public === []) throw new FederationException('El nodo federado no tiene IPv4 pública válida.', 502);
        return $public[0];
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false && $ip !== '169.254.169.254';
    }
}
