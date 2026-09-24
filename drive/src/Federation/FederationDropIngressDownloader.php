<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationDropIngressDownloader
{
    public function download(string $url, int $expectedSize): array
    {
        if (!extension_loaded('curl')) throw new FederationException('cURL es necesario para migrar ingress FederationDrop.', 503);
        if ($expectedSize <= 0 || $expectedSize > FederationReplicaDownloader::MAX_BYTES) {
            throw new FederationException('Tamaño ingress FederationDrop inválido.', 413);
        }

        [$host, $ip] = (new FederationReplicaDownloader())->safeS3Target($url);
        $tmp = tempnam(sys_get_temp_dir(), 'arcadecloud-drop-ingress-');
        if (!is_string($tmp) || $tmp === '') throw new FederationException('No se pudo crear temporal ingress.', 500);
        $fh = fopen($tmp, 'wb');
        if (!is_resource($fh)) {
            @unlink($tmp);
            throw new FederationException('No se pudo abrir temporal ingress.', 500);
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $overflow = false;
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            @unlink($tmp);
            throw new FederationException('No se pudo iniciar migración ingress.', 500);
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
            CURLOPT_USERAGENT => 'ArcadeCloud-FederationDrop-Ingress/1',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($fh, $hash, &$bytes, &$overflow, $expectedSize): int {
                $next = $bytes + strlen($chunk);
                if ($next > $expectedSize || $next > FederationReplicaDownloader::MAX_BYTES) {
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

        if ($overflow || $ok === false || $status !== 200 || $bytes !== $expectedSize) {
            @unlink($tmp);
            throw new FederationException(
                'No se pudo migrar el objeto ingress desde S3'
                . ($error !== '' ? ': ' . $error : '.'),
                502
            );
        }

        return [
            'path' => $tmp,
            'bytes' => $bytes,
            'content_id' => 'sha256:' . hash_final($hash),
        ];
    }
}
