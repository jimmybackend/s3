<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationReplicaDownloader
{
    public const MAX_BYTES = 5368709120; // 5 GiB, límite de PutObject simple.

    public function download(string $url, int $expectedSize, string $contentId): array
    {
        if (!extension_loaded('curl')) throw new FederationException('cURL es necesario para descargar réplicas.', 503);
        if ($expectedSize < 0 || $expectedSize > self::MAX_BYTES) {
            throw new FederationException('La réplica excede el límite de 5 GiB de esta versión.', 413);
        }
        $contentId = strtolower(trim($contentId));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) {
            throw new FederationException('La réplica requiere SHA-256 conocido.', 409);
        }

        [$host, $ip] = $this->safeS3Target($url);
        $tmp = tempnam(sys_get_temp_dir(), 'arcadecloud-replica-');
        if (!is_string($tmp) || $tmp === '') throw new FederationException('No se pudo crear archivo temporal de réplica.', 500);
        $fh = fopen($tmp, 'wb');
        if (!is_resource($fh)) {
            @unlink($tmp);
            throw new FederationException('No se pudo abrir archivo temporal de réplica.', 500);
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $overflow = false;
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            @unlink($tmp);
            throw new FederationException('No se pudo inicializar descarga de réplica.', 500);
        }
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $ip],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_USERAGENT => 'ArcadeCloud-Federation-Replica/1',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($fh, $hash, &$bytes, &$overflow, $expectedSize): int {
                $next = $bytes + strlen($chunk);
                if ($next > $expectedSize || $next > self::MAX_BYTES) {
                    $overflow = true;
                    return 0;
                }
                $written = fwrite($fh, $chunk);
                if ($written !== strlen($chunk)) return 0;
                hash_update($hash, $chunk);
                $bytes = $next;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fflush($fh);
        fclose($fh);

        if ($overflow || $ok === false || $status !== 200) {
            @unlink($tmp);
            throw new FederationException('No se pudo descargar réplica desde S3' . ($error !== '' ? ': ' . $error : '.'), 502);
        }
        if ($bytes !== $expectedSize) {
            @unlink($tmp);
            throw new FederationException('El tamaño descargado no coincide con la oferta firmada.', 409);
        }
        $actual = 'sha256:' . hash_final($hash);
        if (!hash_equals($contentId, $actual)) {
            @unlink($tmp);
            throw new FederationException('El SHA-256 de la réplica no coincide con Content ID.', 409);
        }
        return ['path' => $tmp, 'bytes' => $bytes, 'content_id' => $actual];
    }

    private function safeS3Target(string $url): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new FederationException('La fuente de réplica debe ser una URL HTTPS S3 prefirmada.', 400);
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($port !== 443) throw new FederationException('La descarga de réplica sólo permite HTTPS puerto 443.', 400);
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if (!preg_match('/\A(?:[a-z0-9.-]+\.)?s3(?:[.-][a-z0-9-]+)*\.amazonaws\.com\z/', $host)) {
            throw new FederationException('La fuente de réplica no corresponde a un endpoint S3 permitido.', 400);
        }
        $records = @dns_get_record($host, DNS_A);
        if (!is_array($records) || $records === []) throw new FederationException('No se pudo resolver endpoint S3 de réplica.', 502);
        $public = [];
        foreach ($records as $record) {
            $ip = (string)($record['ip'] ?? '');
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new FederationException('Endpoint S3 resolvió a una IP no pública.', 400);
            }
            $public[] = $ip;
        }
        return [$host, $public[0]];
    }
}
