<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use JsonException;

final class FederationDropStripeClient
{
    /** @var null|callable */
    private $requester;

    public function __construct(
        private readonly string $apiKey,
        ?callable $requester = null
    ) {
        if (!preg_match('/^(?:sk|rk)_(?:test|live)_[A-Za-z0-9_]+$/', $this->apiKey)) {
            throw new FederationException('Formato de clave Stripe inválido.', 503);
        }
        $this->requester = $requester;
    }

    public function createCheckoutSession(array $params): array
    {
        [$status, $body] = $this->request(
            'POST',
            'https://api.stripe.com/v1/checkout/sessions',
            ['content-type' => 'application/x-www-form-urlencoded'],
            http_build_query($params, '', '&', PHP_QUERY_RFC3986)
        );

        if ($status < 200 || $status >= 300) {
            throw new FederationException('Stripe no pudo crear la sesión de Checkout.', 502);
        }

        $json = self::jsonObject($body);
        $id = $json['id'] ?? null;
        $url = $json['url'] ?? null;
        if (!is_string($id) || !str_starts_with($id, 'cs_')
            || !is_string($url) || !str_starts_with($url, 'https://checkout.stripe.com/')) {
            throw new FederationException('Stripe devolvió una sesión de Checkout inválida.', 502);
        }
        return $json;
    }

    public function expectedLiveMode(): bool
    {
        return str_contains($this->apiKey, '_live_');
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method, string $url, array $headers, string $body = ''): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['authorization'] = 'Basic ' . base64_encode($this->apiKey . ':');

        if ($this->requester !== null) {
            $result = ($this->requester)(strtoupper($method), $url, $headers, $body);
            if (!is_array($result) || count($result) < 2) {
                throw new FederationException('Respuesta sintética Stripe inválida.', 500);
            }
            return [(int)$result[0], (string)$result[1], is_array($result[2] ?? null) ? $result[2] : []];
        }

        if (!function_exists('curl_init')) {
            throw new FederationException('FederationDrop Stripe requiere la extensión PHP cURL.', 503);
        }

        $wire = [];
        foreach ($headers as $name => $value) $wire[] = $name . ': ' . $value;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $wire,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new FederationException('Error HTTP hacia Stripe: ' . $error, 502);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string)$response, []];
    }

    private static function jsonObject(string $body): array
    {
        try {
            $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('Stripe devolvió JSON inválido.', 502);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new FederationException('Stripe devolvió un objeto JSON inválido.', 502);
        }
        return $value;
    }
}
