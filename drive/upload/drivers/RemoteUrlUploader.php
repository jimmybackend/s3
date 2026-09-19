<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class RemoteUrlUploader implements UploaderInterface
{
    private const MAX_BYTES = 5368709120; // 5 GiB
    private const MAX_REDIRECTS = 5;
    private const PART_SIZE = 8388608; // 8 MiB

    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private StorageObjectNameCodec $codec
    ) {
    }

    private function decodeUrl(array $req): string
    {
        $u64 = (string)($req['u64'] ?? '');
        if ($u64 !== '') {
            $decoded = base64_decode($u64, true);
            if ($decoded === false) {
                throw new RuntimeException('u64 inválido');
            }
            $url = trim($decoded);
        } else {
            $url = trim((string)($req['url'] ?? ''));
        }

        if ($url === '') {
            throw new RuntimeException('Falta parámetro url/u64');
        }

        return $url;
    }

    private function inspectRemote(string $initialUrl): array
    {
        $url = $initialUrl;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            [$safeUrl, $host, $ip] = $this->safeTarget($url);
            $headers = [];

            $head = curl_init($safeUrl);
            if ($head === false) {
                throw new RuntimeException('No se pudo inicializar cURL');
            }

            curl_setopt_array($head, [
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$host . ':443:' . $ip],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                    $length = strlen($header);
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $length;
                },
                CURLOPT_USERAGENT => 'ArcadeCloud-RemoteUrlUploader/2',
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
            ]);

            $ok = curl_exec($head);
            $status = (int)curl_getinfo($head, CURLINFO_RESPONSE_CODE);
            $error = curl_error($head);
            curl_close($head);

            if ($ok === false) {
                throw new RuntimeException('No se pudo consultar la URL remota' . ($error !== '' ? ': ' . $error : '.'));
            }

            if ($status >= 300 && $status < 400) {
                $location = trim((string)($headers['location'] ?? ''));
                if ($location === '') {
                    throw new RuntimeException('La URL remota redirige sin destino válido.');
                }
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new RuntimeException('La URL remota excede el máximo de redirecciones permitido.');
                }
                $url = $this->resolveRedirect($safeUrl, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('La URL remota respondió HTTP ' . $status . '.');
            }

            $contentLength = trim((string)($headers['content-length'] ?? ''));
            if ($contentLength !== '' && ctype_digit($contentLength) && (int)$contentLength > self::MAX_BYTES) {
                throw new RuntimeException('El archivo remoto excede el límite de 5 GiB.');
            }

            return [
                'url' => $safeUrl,
                'host' => $host,
                'ip' => $ip,
                'headers' => $headers,
            ];
        }

        throw new RuntimeException('No se pudo resolver la URL remota.');
    }

    private function safeTarget(string $url): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
        ) {
            throw new RuntimeException('La subida remota sólo permite URLs HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('La URL remota contiene componentes no permitidos.');
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($port !== 443) {
            throw new RuntimeException('La subida remota sólo permite HTTPS en el puerto 443.');
        }

        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($host === '' || strlen($host) > 253 || !preg_match('/\A[a-z0-9.-]+\z/', $host)) {
            throw new RuntimeException('Host remoto inválido.');
        }

        $ip = $this->resolvePublicIpv4($host);

        return [$url, $host, $ip];
    }

    private function resolvePublicIpv4(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!$this->isPublicIpv4($host)) {
                throw new RuntimeException('No se permiten destinos privados, reservados o de metadatos.');
            }
            return $host;
        }

        $records = @dns_get_record($host, DNS_A);
        if (!is_array($records) || $records === []) {
            throw new RuntimeException('No se pudo resolver el host remoto.');
        }

        $public = [];
        foreach ($records as $record) {
            $ip = (string)($record['ip'] ?? '');
            if ($ip === '') {
                continue;
            }
            if (!$this->isPublicIpv4($ip)) {
                throw new RuntimeException('El DNS remoto contiene una IP privada o reservada.');
            }
            $public[] = $ip;
        }

        if ($public === []) {
            throw new RuntimeException('El host remoto no tiene una IPv4 pública válida.');
        }

        return $public[0];
    }

    private function isPublicIpv4(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false
            && $ip !== '169.254.169.254';
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        $location = trim($location);

        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }

        $locationParts = parse_url($location);
        if (is_array($locationParts) && isset($locationParts['scheme'])) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['host'])) {
            throw new RuntimeException('No se pudo resolver la redirección remota.');
        }

        $origin = 'https://' . (string)$base['host'];
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = (string)($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        if ($directory === '.' || $directory === '/') {
            $directory = '';
        }

        return $origin . $directory . '/' . $location;
    }

    private function guessFilename(string $url, array $headers): string
    {
        $contentDisposition = (string)($headers['content-disposition'] ?? '');
        $candidate = '';

        if ($contentDisposition !== '') {
            if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $contentDisposition, $match)) {
                $candidate = urldecode(trim($match[1], "\"' "));
            } elseif (preg_match('/filename=\"?([^\";]+)\"?/i', $contentDisposition, $match)) {
                $candidate = trim($match[1], "\"' ");
            }
        }

        if ($candidate === '') {
            $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
            $candidate = basename($path);
        }

        $candidate = basename(str_replace('\\', '/', $candidate));
        $candidate = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $candidate) ?? '');

        if ($candidate === '' || $candidate === '.' || $candidate === '..') {
            $candidate = 'archivo';
        }

        return mb_substr($candidate, 0, 255);
    }

    private function metadataUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }

        return 'https://' . (string)$parts['host'] . (string)($parts['path'] ?? '/');
    }

    public function init(array $req): array
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $inspected = $this->inspectRemote($this->decodeUrl($req));
        $url = (string)$inspected['url'];
        $host = (string)$inspected['host'];
        $ip = (string)$inspected['ip'];
        $headers = is_array($inspected['headers']) ? $inspected['headers'] : [];

        $contentType = trim((string)($headers['content-type'] ?? 'application/octet-stream'));
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }

        $nombreOriginal = $this->guessFilename($url, $headers);

        $carpeta = trim((string)($req['ruta_objetivo'] ?? ''), '/');
        if ($carpeta === '') {
            throw new RuntimeException('Falta ruta_objetivo');
        }

        $userId = (int)($req['_user_id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Usuario de subida inválido.');
        }

        $nombreEncriptado = $this->codec->createFileObjectName($nombreOriginal);
        $key = $carpeta . '/' . $nombreEncriptado;

        $buffer = '';
        $uploadId = null;
        $parts = [];
        $partNumber = 1;
        $bytes = 0;
        $overflow = false;

        $stream = curl_init($url);
        if ($stream === false) {
            throw new RuntimeException('No se pudo inicializar cURL streaming');
        }

        $s3 = $this->s3;
        $bucket = $this->bucket;

        curl_setopt_array($stream, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $ip],
            CURLOPT_USERAGENT => 'ArcadeCloud-RemoteUrlUploader/2',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (
                &$buffer,
                &$uploadId,
                &$parts,
                &$partNumber,
                $s3,
                $bucket,
                $key,
                $contentType,
                $nombreOriginal,
                &$bytes,
                &$overflow
            ): int {
                $next = $bytes + strlen($data);
                if ($next > self::MAX_BYTES) {
                    $overflow = true;
                    return 0;
                }

                $buffer .= $data;
                $bytes = $next;

                if ($uploadId === null && strlen($buffer) >= self::PART_SIZE) {
                    $init = $s3->createMultipartUpload([
                        'Bucket' => $bucket,
                        'Key' => $key,
                        'ContentType' => $contentType,
                        'ACL' => 'private',
                        'Metadata' => ['OriginalName' => mb_substr($nombreOriginal, 0, 1024)],
                    ]);
                    $uploadId = (string)$init['UploadId'];
                }

                while ($uploadId !== null && strlen($buffer) >= self::PART_SIZE) {
                    $partData = substr($buffer, 0, self::PART_SIZE);
                    $buffer = substr($buffer, self::PART_SIZE);
                    $result = $s3->uploadPart([
                        'Bucket' => $bucket,
                        'Key' => $key,
                        'UploadId' => $uploadId,
                        'PartNumber' => $partNumber,
                        'Body' => $partData,
                    ]);
                    $parts[] = ['PartNumber' => $partNumber, 'ETag' => $result['ETag']];
                    $partNumber++;
                }

                return strlen($data);
            },
        ]);

        $ok = curl_exec($stream);
        $httpStatus = (int)curl_getinfo($stream, CURLINFO_RESPONSE_CODE);
        $error = curl_error($stream);
        curl_close($stream);

        if ($overflow || $ok === false || $httpStatus < 200 || $httpStatus >= 300) {
            if ($uploadId !== null) {
                try {
                    $this->s3->abortMultipartUpload([
                        'Bucket' => $this->bucket,
                        'Key' => $key,
                        'UploadId' => $uploadId,
                    ]);
                } catch (Throwable) {
                    // El error original de red/tamaño tiene prioridad.
                }
            }

            if ($overflow) {
                throw new RuntimeException('El archivo remoto excede el límite de 5 GiB.');
            }

            throw new RuntimeException(
                'Error descargando URL remota'
                . ($httpStatus > 0 ? ' (HTTP ' . $httpStatus . ')' : '')
                . ($error !== '' ? ': ' . $error : '.')
            );
        }

        if ($uploadId === null) {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $buffer,
                'ACL' => 'private',
                'ContentType' => $contentType,
                'Metadata' => ['OriginalName' => mb_substr($nombreOriginal, 0, 1024)],
            ]);
        } else {
            if ($buffer !== '') {
                $result = $this->s3->uploadPart([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                    'PartNumber' => $partNumber,
                    'Body' => $buffer,
                ]);
                $parts[] = ['PartNumber' => $partNumber, 'ETag' => $result['ETag']];
            }

            $this->s3->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts],
            ]);
        }

        $metadatos = json_encode([
            'source_url' => $this->metadataUrl($url),
            'content_type' => $contentType,
            'bytes' => $bytes,
            'ip_origen' => (string)($req['_remote_addr'] ?? '0.0.0.0'),
            'usuario_envio' => (string)($req['_usuario'] ?? 'usuario'),
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $repo = new FileS3Repository($this->db);
        $fileId = $repo->insertFile([
            'Nombre' => $nombreOriginal,
            'Encriptado' => $nombreEncriptado,
            'Tamano' => $bytes,
            'Metadatos' => $metadatos,
            'Ruta' => rtrim($carpeta, '/') . '/',
            'Found' => 1,
            'AccessType' => 'normal',
            'Fecha' => date('Y-m-d H:i:s'),
            'user_id_' => $userId,
        ]);

        return [
            'ok' => true,
            'key' => $key,
            'bytes' => $bytes,
            'file_id' => $fileId,
            'nombreOriginal' => $nombreOriginal,
            'nombreEncriptado' => $nombreEncriptado,
            'carpeta' => $carpeta,
        ];
    }

    public function part(array $req): array
    {
        return ['ok' => true];
    }

    public function complete(array $req): array
    {
        return ['ok' => true];
    }
}
