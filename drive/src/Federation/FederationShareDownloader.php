<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationShareDownloader
{
    private const MAX_BYTES = 5368709120; // 5 GiB

    public function download(string $accessUrl): array
    {
        if (!extension_loaded('curl')) {
            throw new FederationException('La extensión cURL es necesaria para copiar archivos compartidos.', 503);
        }

        $directUrl = $this->withDownloadFlag($accessUrl);
        $location = $this->resolveSingleRedirect($directUrl);
        $this->assertS3Url($location);

        $tmp = tempnam(sys_get_temp_dir(), 'arcadecloud-share-');
        if (!is_string($tmp) || $tmp === '') throw new FederationException('No se pudo crear archivo temporal para la copia.', 500);
        $fp = fopen($tmp, 'wb');
        if (!is_resource($fp)) {
            @unlink($tmp);
            throw new FederationException('No se pudo abrir archivo temporal para la copia.', 500);
        }

        $written = 0;
        $contentType = 'application/octet-stream';
        try {
            [$url, $host, $ip] = $this->safeTarget($location, true);
            $ch = curl_init($url);
            if ($ch === false) throw new FederationException('No se pudo inicializar descarga compartida.', 500);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT_MS => 3000,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$host . ':443:' . $ip],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($fp, &$written): int {
                    $next = $written + strlen($chunk);
                    if ($next > self::MAX_BYTES) return 0;
                    $bytes = fwrite($fp, $chunk);
                    if ($bytes === false) return 0;
                    $written += $bytes;
                    return $bytes;
                },
                CURLOPT_USERAGENT => 'ArcadeCloud-Federation-ShareImport/1',
            ]);
            $ok = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($ok === false || $http < 200 || $http >= 300) {
                throw new FederationException('No se pudo descargar el archivo compartido' . ($error !== '' ? ': ' . $error : '.'), 502);
            }
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($tmp);
            if ($e instanceof FederationException) throw $e;
            throw new FederationException('No se pudo descargar el archivo compartido.', 502);
        }
        fclose($fp);

        if ($written <= 0) {
            @unlink($tmp);
            throw new FederationException('El archivo compartido está vacío o no pudo descargarse.', 502);
        }

        return [
            'path' => $tmp,
            'size_bytes' => $written,
            'sha256' => hash_file('sha256', $tmp) ?: null,
            'content_type' => $contentType !== '' ? preg_replace('/;.*$/', '', $contentType) : 'application/octet-stream',
        ];
    }

    private function resolveSingleRedirect(string $accessUrl): string
    {
        [$url, $host, $ip] = $this->safeTarget($accessUrl, false);
        $headers = [];
        $bodyBytes = 0;
        $ch = curl_init($url);
        if ($ch === false) throw new FederationException('No se pudo iniciar resolución del Share.', 500);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $ip],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    if ($name !== '') $headers[$name] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$bodyBytes): int {
                $bodyBytes += strlen($chunk);
                return $bodyBytes <= 65536 ? strlen($chunk) : 0;
            },
            CURLOPT_USERAGENT => 'ArcadeCloud-Federation-ShareImport/1',
        ]);
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($ok === false && $http === 0) {
            throw new FederationException('El nodo propietario no respondió' . ($error !== '' ? ': ' . $error : '.'), 502);
        }
        if ($http < 300 || $http >= 400 || !is_string($headers['location'] ?? null)) {
            throw new FederationException('El Share no entregó una ubicación de descarga válida.', 502);
        }
        return (string)$headers['location'];
    }

    private function withDownloadFlag(string $url): string
    {
        $url = trim($url);
        if ($url === '') throw new FederationException('El Share no tiene URL de acceso.', 409);
        return $url . (str_contains($url, '?') ? '&' : '?') . 'download=1';
    }

    private function assertS3Url(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!preg_match('/\A(?:[a-z0-9.-]+\.)?s3(?:[.-][a-z0-9-]+)*\.amazonaws\.com\z/', $host)) {
            throw new FederationException('La descarga compartida no apunta a un endpoint S3 permitido.', 502);
        }
    }

    private function safeTarget(string $url, bool $requireS3): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])) {
            throw new FederationException('La descarga compartida debe usar HTTPS.', 400);
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new FederationException('URL compartida no permitida.', 400);
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($port !== 443) throw new FederationException('Sólo se permite HTTPS puerto 443.', 400);
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($host === '' || strlen($host) > 253 || !preg_match('/\A[a-z0-9.-]+\z/', $host)) {
            throw new FederationException('Host compartido inválido.', 400);
        }
        if ($requireS3 && !preg_match('/\A(?:[a-z0-9.-]+\.)?s3(?:[.-][a-z0-9-]+)*\.amazonaws\.com\z/', $host)) {
            throw new FederationException('Host S3 compartido inválido.', 400);
        }
        $ip = $this->resolvePublicIpv4($host);
        return [$url, $host, $ip];
    }

    private function resolvePublicIpv4(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!$this->isPublicIp($host)) throw new FederationException('IP privada/reservada no permitida.', 400);
            return $host;
        }
        $records = @dns_get_record($host, DNS_A);
        if (!is_array($records) || $records === []) throw new FederationException('No se pudo resolver el host compartido.', 502);
        foreach ($records as $record) {
            $ip = (string)($record['ip'] ?? '');
            if ($ip === '') continue;
            if (!$this->isPublicIp($ip)) throw new FederationException('DNS compartido contiene IP privada/reservada.', 400);
            return $ip;
        }
        throw new FederationException('El host compartido no tiene IPv4 pública válida.', 502);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && $ip !== '169.254.169.254';
    }
}
