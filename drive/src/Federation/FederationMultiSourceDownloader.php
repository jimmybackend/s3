<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

final class FederationMultiSourceDownloader
{
    public const MAX_SOURCES = 4;

    public function __construct(private ?FederationReplicaDownloader $single = null)
    {
        $this->single ??= new FederationReplicaDownloader();
    }

    /**
     * Descarga un mismo objeto desde varias réplicas mediante rangos HTTP
     * concurrentes. Con 2+ fuentes válidas ninguna fuente elegida transporta
     * el archivo completo, salvo que un rango necesite failover.
     *
     * @param array<int,string> $urls
     */
    public function download(array $urls, int $expectedSize, string $contentId): array
    {
        $urls = array_values(array_unique(array_filter(array_map(
            static fn(mixed $url): string => trim((string)$url),
            $urls
        ), static fn(string $url): bool => $url !== '')));

        if ($expectedSize < 0 || $expectedSize > FederationReplicaDownloader::MAX_BYTES) {
            throw new FederationException('El transporte federado excede el límite de 5 GiB.', 413);
        }
        $contentId = strtolower(trim($contentId));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) {
            throw new FederationException('El transporte federado requiere Content ID SHA-256.', 409);
        }
        if ($urls === []) throw new FederationException('No hay fuentes federadas disponibles.', 503);

        $urls = array_slice($urls, 0, self::MAX_SOURCES);
        if (count($urls) === 1 || $expectedSize <= 1 || !function_exists('curl_multi_init')) {
            $result = $this->single->download($urls[0], $expectedSize, $contentId);
            $result['sources_used'] = 1;
            $result['parallel'] = false;
            return $result;
        }

        $sourceCount = min(count($urls), $expectedSize);
        $segments = $this->segments($expectedSize, $sourceCount);
        $parts = $this->downloadConcurrent($urls, $segments);

        $tmp = tempnam(sys_get_temp_dir(), 'arcadecloud-multisource-');
        if (!is_string($tmp) || $tmp === '') {
            foreach ($parts as $part) @unlink((string)$part['path']);
            throw new FederationException('No se pudo crear archivo temporal multisource.', 500);
        }

        $out = fopen($tmp, 'wb');
        if (!is_resource($out)) {
            foreach ($parts as $part) @unlink((string)$part['path']);
            @unlink($tmp);
            throw new FederationException('No se pudo abrir archivo temporal multisource.', 500);
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $nodesUsed = [];
        try {
            ksort($parts);
            foreach ($parts as $part) {
                $in = fopen((string)$part['path'], 'rb');
                if (!is_resource($in)) throw new FederationException('No se pudo ensamblar un segmento federado.', 500);
                try {
                    while (!feof($in)) {
                        $chunk = fread($in, 1024 * 1024);
                        if ($chunk === false) throw new FederationException('No se pudo leer un segmento federado.', 500);
                        if ($chunk === '') continue;
                        if (fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new FederationException('No se pudo ensamblar el archivo federado.', 500);
                        }
                        hash_update($hash, $chunk);
                        $bytes += strlen($chunk);
                    }
                } finally {
                    fclose($in);
                }
                $nodesUsed[(string)$part['url']] = true;
            }
        } finally {
            fclose($out);
            foreach ($parts as $part) @unlink((string)$part['path']);
        }

        if ($bytes !== $expectedSize) {
            @unlink($tmp);
            throw new FederationException('El archivo multisource no coincide con el tamaño esperado.', 409);
        }
        $actual = 'sha256:' . hash_final($hash);
        if (!hash_equals($contentId, $actual)) {
            @unlink($tmp);
            throw new FederationException('El SHA-256 multisource no coincide con Content ID.', 409);
        }

        return [
            'path' => $tmp,
            'bytes' => $bytes,
            'content_id' => $actual,
            'sources_used' => count($nodesUsed),
            'parallel' => count($nodesUsed) > 1,
        ];
    }

    /** @return array<int,array{start:int,end:int}> */
    private function segments(int $size, int $count): array
    {
        $base = intdiv($size, $count);
        $extra = $size % $count;
        $segments = [];
        $cursor = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = $base + ($i < $extra ? 1 : 0);
            $segments[] = ['start' => $cursor, 'end' => $cursor + $length - 1];
            $cursor += $length;
        }
        return $segments;
    }

    /**
     * @param array<int,string> $urls
     * @param array<int,array{start:int,end:int}> $segments
     * @return array<int,array{path:string,bytes:int,url:string}>
     */
    private function downloadConcurrent(array $urls, array $segments): array
    {
        $multi = curl_multi_init();
        if ($multi === false) throw new FederationException('No se pudo iniciar transporte multisource.', 500);

        $states = [];
        try {
            foreach ($segments as $index => $segment) {
                $url = $urls[$index % count($urls)];
                $states[$index] = $this->createRangeHandle(
                    $multi,
                    $url,
                    (int)$segment['start'],
                    (int)$segment['end']
                );
            }

            do {
                $code = curl_multi_exec($multi, $running);
                if ($code !== CURLM_OK) {
                    throw new FederationException('Falló el transporte concurrente FederationCloud.', 502);
                }
                if ($running > 0) {
                    $selected = curl_multi_select($multi, 1.0);
                    if ($selected === -1) usleep(10000);
                }
            } while ($running > 0);

            $parts = [];
            foreach ($states as $index => $state) {
                $ch = $state['handle'];
                fflush($state['fh']);
                fclose($state['fh']);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $curlError = curl_error($ch);
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);

                $expected = (int)$state['expected'];
                $ok = !$state['overflow']
                    && $status === 206
                    && (int)$state['bytes'] === $expected
                    && $curlError === '';

                if ($ok) {
                    $parts[$index] = [
                        'path' => (string)$state['path'],
                        'bytes' => (int)$state['bytes'],
                        'url' => (string)$state['url'],
                    ];
                    continue;
                }

                @unlink((string)$state['path']);
                // Si una fuente falla, sólo ese rango se reintenta contra las
                // demás copias; no se reinicia el archivo completo.
                $segment = $segments[$index];
                $parts[$index] = $this->downloadRangeWithFallback(
                    $urls,
                    ($index + 1) % count($urls),
                    (int)$segment['start'],
                    (int)$segment['end']
                );
            }
            return $parts;
        } catch (\Throwable $e) {
            foreach ($states as $state) {
                if (is_resource($state['fh'] ?? null)) @fclose($state['fh']);
                if (isset($state['handle'])) {
                    @curl_multi_remove_handle($multi, $state['handle']);
                    @curl_close($state['handle']);
                }
                if (isset($state['path'])) @unlink((string)$state['path']);
            }
            if ($e instanceof FederationException) throw $e;
            throw new FederationException('No se pudo completar el transporte multisource.', 502);
        } finally {
            curl_multi_close($multi);
        }
    }

    /** @return array<string,mixed> */
    private function createRangeHandle($multi, string $url, int $start, int $end): array
    {
        [$host, $ip] = $this->single->safeS3Target($url);
        $expected = $end - $start + 1;
        $tmp = tempnam(sys_get_temp_dir(), 'arcadecloud-range-');
        if (!is_string($tmp) || $tmp === '') throw new FederationException('No se pudo crear segmento temporal.', 500);
        $fh = fopen($tmp, 'wb');
        if (!is_resource($fh)) {
            @unlink($tmp);
            throw new FederationException('No se pudo abrir segmento temporal.', 500);
        }

        $state = [
            'path' => $tmp,
            'fh' => $fh,
            'bytes' => 0,
            'overflow' => false,
            'expected' => $expected,
            'url' => $url,
            'handle' => null,
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            @unlink($tmp);
            throw new FederationException('No se pudo iniciar rango federado.', 500);
        }
        $state['handle'] = $ch;

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $ip],
            CURLOPT_RANGE => $start . '-' . $end,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_USERAGENT => 'ArcadeCloud-Federation-MultiSource/1',
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$state): int {
                $next = (int)$state['bytes'] + strlen($chunk);
                if ($next > (int)$state['expected']) {
                    $state['overflow'] = true;
                    return 0;
                }
                $written = fwrite($state['fh'], $chunk);
                if ($written !== strlen($chunk)) return 0;
                $state['bytes'] = $next;
                return strlen($chunk);
            },
        ]);
        curl_multi_add_handle($multi, $ch);

        return $state;
    }

    private function downloadRangeWithFallback(array $urls, int $preferred, int $start, int $end): array
    {
        $ordered = [];
        $count = count($urls);
        for ($offset = 0; $offset < $count; $offset++) {
            $ordered[] = $urls[($preferred + $offset) % $count];
        }

        foreach ($ordered as $url) {
            try {
                return $this->downloadRange($url, $start, $end);
            } catch (FederationException) {
                // prueba la siguiente copia
            }
        }
        throw new FederationException(
            'Ninguna réplica pudo entregar el rango ' . $start . '-' . $end . '.',
            502
        );
    }

    private function downloadRange(string $url, int $start, int $end): array
    {
        if (!extension_loaded('curl')) throw new FederationException('cURL es necesario para transporte multisource.', 503);
        [$host, $ip] = $this->single->safeS3Target($url);
        $expected = $end - $start + 1;
        $tmp = tempnam(sys_get_temp_dir(), 'arcadecloud-range-');
        if (!is_string($tmp) || $tmp === '') throw new FederationException('No se pudo crear segmento temporal.', 500);
        $fh = fopen($tmp, 'wb');
        if (!is_resource($fh)) {
            @unlink($tmp);
            throw new FederationException('No se pudo abrir segmento temporal.', 500);
        }

        $bytes = 0;
        $overflow = false;
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            @unlink($tmp);
            throw new FederationException('No se pudo iniciar rango federado.', 500);
        }
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $ip],
            CURLOPT_RANGE => $start . '-' . $end,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_USERAGENT => 'ArcadeCloud-Federation-MultiSource/1',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($fh, &$bytes, &$overflow, $expected): int {
                $next = $bytes + strlen($chunk);
                if ($next > $expected) {
                    $overflow = true;
                    return 0;
                }
                $written = fwrite($fh, $chunk);
                if ($written !== strlen($chunk)) return 0;
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

        if ($overflow || $ok === false || $status !== 206 || $bytes !== $expected) {
            @unlink($tmp);
            throw new FederationException(
                'Una réplica no pudo entregar su rango'
                . ($error !== '' ? ': ' . $error : '.'),
                502
            );
        }
        return ['path' => $tmp, 'bytes' => $bytes, 'url' => $url];
    }
}
