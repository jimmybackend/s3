<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationEndpointPlan
{
    /**
     * @param array<string,mixed> $config
     * @return array{mode:string,identifier:string,public_url:string,federation_url:string,cert_name:string,shortlived:bool,tls_email:string,webroot:string,public_path:string}
     */
    public function resolve(array $config, ?string $detectedPublicIpv4 = null): array
    {
        $mode = strtolower(trim((string)($config['mode'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'domain', 'ip'], true)) {
            throw new FederationException('El modo de endpoint debe ser auto, domain o ip.', 400);
        }

        $domain = $this->domain((string)($config['domain'] ?? ''));
        $configuredIp = $this->publicIpv4((string)($config['public_ip'] ?? ''), true);
        $detectedIp = $this->publicIpv4((string)($detectedPublicIpv4 ?? ''), true);
        $publicPath = $this->publicPath((string)($config['public_path'] ?? ''));
        $tlsEmail = trim((string)($config['tls_email'] ?? ''));
        $webroot = trim((string)($config['webroot'] ?? ''));

        if ($tlsEmail === '' || !filter_var($tlsEmail, FILTER_VALIDATE_EMAIL)) {
            throw new FederationException('Configura un correo válido para Certbot/Let’s Encrypt.', 400);
        }
        if ($webroot === '' || $webroot[0] !== '/' || str_contains($webroot, "\0") || str_contains($webroot, '/../')) {
            throw new FederationException('El webroot del endpoint FederationCloud debe ser una ruta absoluta segura.', 400);
        }

        $resolvedMode = $mode;
        if ($mode === 'auto') {
            $resolvedMode = $domain !== '' ? 'domain' : 'ip';
        }

        if ($resolvedMode === 'domain') {
            if ($domain === '') {
                throw new FederationException('El modo domain requiere un dominio público.', 400);
            }
            $identifier = $domain;
        } else {
            $identifier = $detectedIp !== '' ? $detectedIp : $configuredIp;
            if ($identifier === '') {
                throw new FederationException('No se pudo determinar una IPv4 pública para el nodo FederationCloud.', 503);
            }
        }

        $publicUrl = 'https://' . $identifier . $publicPath;
        $federationUrl = rtrim($publicUrl, '/') . '/federationcloud/';

        return [
            'mode' => $resolvedMode,
            'identifier' => $identifier,
            'public_url' => $publicUrl,
            'federation_url' => $federationUrl,
            'cert_name' => 'arcadecloud-federation-' . $resolvedMode,
            'shortlived' => $resolvedMode === 'ip',
            'tls_email' => $tlsEmail,
            'webroot' => rtrim($webroot, '/'),
            'public_path' => $publicPath,
        ];
    }

    private function domain(string $value): string
    {
        $value = strtolower(rtrim(trim($value), '.'));
        if ($value === '') return '';
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            throw new FederationException('El campo domain debe contener un dominio, no una IP.', 400);
        }
        if (strlen($value) > 253 || !str_contains($value, '.') || !preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $value)) {
            throw new FederationException('Dominio FederationCloud inválido.', 400);
        }
        foreach (explode('.', $value) as $label) {
            if ($label === '' || strlen($label) > 63 || $label[0] === '-' || str_ends_with($label, '-')) {
                throw new FederationException('Dominio FederationCloud inválido.', 400);
            }
        }
        return $value;
    }

    private function publicIpv4(string $value, bool $allowEmpty): string
    {
        $value = trim($value);
        if ($value === '' && $allowEmpty) return '';
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($value, FILTER_VALIDATE_IP, $flags) === false) {
            throw new FederationException('La IP FederationCloud debe ser una IPv4 pública enrutable.', 400);
        }
        return $value;
    }

    private function publicPath(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '/') return '';
        if ($value[0] !== '/' || str_contains($value, "\0") || str_contains($value, '..') || str_contains($value, '?') || str_contains($value, '#')) {
            throw new FederationException('Ruta pública FederationCloud inválida.', 400);
        }
        $value = preg_replace('~/+~', '/', $value) ?? $value;
        return rtrim($value, '/');
    }
}
