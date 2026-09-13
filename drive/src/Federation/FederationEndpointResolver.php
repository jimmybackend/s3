<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationEndpointResolver
{
    /**
     * Decide la dirección pública del nodo sin cambiar su identidad criptográfica.
     *
     * Prioridad:
     *  1. cualquier hostname DNS ya configurado en federation/public URL;
     *  2. IPv4 pública detectada actualmente (EC2 IMDSv2);
     *  3. IPv4 pública previamente configurada, sólo como fallback.
     *
     * @return array{mode:string,host:string,public_url:string,federation_url:string,ip_changed:bool}
     */
    public function resolve(string $configuredPublicUrl, string $configuredFederationUrl, ?string $detectedPublicIpv4): array
    {
        $publicHost = $this->hostFromUrl($configuredPublicUrl);
        $federationHost = $this->hostFromUrl($configuredFederationUrl);

        foreach ([$federationHost, $publicHost] as $candidate) {
            if ($candidate !== null && !$this->isIp($candidate)) {
                $host = $this->normalizeDomain($candidate);
                return $this->result('domain', $host, false);
            }
        }

        $detected = $detectedPublicIpv4 !== null ? trim($detectedPublicIpv4) : '';
        if ($detected !== '' && $this->isPublicIpv4($detected)) {
            $previousIp = null;
            foreach ([$federationHost, $publicHost] as $candidate) {
                if ($candidate !== null && $this->isPublicIpv4($candidate)) {
                    $previousIp = $candidate;
                    break;
                }
            }
            return $this->result('dynamic_ip', $detected, $previousIp !== null && !hash_equals($previousIp, $detected));
        }

        foreach ([$federationHost, $publicHost] as $candidate) {
            if ($candidate !== null && $this->isPublicIpv4($candidate)) {
                return $this->result('dynamic_ip', $candidate, false);
            }
        }

        throw new FederationException(
            'No se encontró dominio FederationCloud ni una IPv4 pública válida para publicar el nodo.',
            503
        );
    }

    private function result(string $mode, string $host, bool $ipChanged): array
    {
        return [
            'mode' => $mode,
            'host' => $host,
            'public_url' => 'https://' . $host,
            'federation_url' => 'https://' . $host . '/federationcloud/',
            'ip_changed' => $ipChanged,
        ];
    }

    private function hostFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;

        $parts = parse_url($url);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {
            throw new FederationException('La URL FederationCloud configurada no contiene un host válido.', 500);
        }

        $host = strtolower(rtrim(trim((string)$parts['host']), '.'));
        if ($host === '') {
            throw new FederationException('La URL FederationCloud configurada no contiene un host válido.', 500);
        }
        return $host;
    }

    private function normalizeDomain(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if (strlen($host) < 1 || strlen($host) > 253 || $this->isIp($host)) {
            throw new FederationException('Dominio FederationCloud inválido.', 500);
        }
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/', $host) || str_contains($host, '..')) {
            throw new FederationException('Dominio FederationCloud inválido.', 500);
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || $label[0] === '-' || str_ends_with($label, '-')) {
                throw new FederationException('Dominio FederationCloud inválido.', 500);
            }
        }
        return $host;
    }

    private function isIp(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private function isPublicIpv4(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
