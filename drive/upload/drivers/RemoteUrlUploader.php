<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class RemoteUrlUploader implements UploaderInterface
{
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
            return trim($decoded);
        }

        $url = trim((string)($req['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('Falta parámetro url/u64');
        }

        return $url;
    }

    private function guessFilename(string $url, array $headers): string
    {
        $contentDisposition = $headers['content-disposition'] ?? '';
        if ($contentDisposition) {
            if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $contentDisposition, $match)) {
                return urldecode(trim($match[1], "\"' "));
            }
            if (preg_match('/filename="?([^";]+)"?/i', $contentDisposition, $match)) {
                return trim($match[1], "\"' ");
            }
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $base = basename($path);
        if ($base && $base !== '/' && strpos($base, '.') !== false) {
            return $base;
        }

        return 'archivo';
    }

    public function init(array $req): array
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $url = $this->decodeUrl($req);
        $headers = [];

        $head = curl_init($url);
        if (!$head) {
            throw new RuntimeException('No se pudo inicializar cURL');
        }

        curl_setopt_array($head, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, $header) use (&$headers) {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; RemoteUrlUploader/1.0)',
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 60,
        ]);
        @curl_exec($head);
        curl_close($head);

        $contentType = (string)($headers['content-type'] ?? 'application/octet-stream');
        $nombreOriginal = $this->guessFilename($url, $headers);

        $carpeta = trim((string)($req['ruta_objetivo'] ?? ''), '/');
        if ($carpeta === '') {
            throw new RuntimeException('Falta ruta_objetivo');
        }

        $nombreEncriptado = $this->codec->createFileObjectName($nombreOriginal);
        $key = $carpeta . '/' . $nombreEncriptado;

        $partSize = 8 * 1024 * 1024;
        $buffer = '';
        $uploadId = null;
        $parts = [];
        $partNumber = 1;
        $bytes = 0;

        $stream = curl_init($url);
        if (!$stream) {
            throw new RuntimeException('No se pudo inicializar cURL streaming');
        }

        $s3 = $this->s3;
        $bucket = $this->bucket;

        curl_setopt_array($stream, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; RemoteUrlUploader/1.0)',
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => static function ($curl, $data) use (
                &$buffer,
                $partSize,
                &$uploadId,
                &$parts,
                &$partNumber,
                $s3,
                $bucket,
                $key,
                $contentType,
                $nombreOriginal,
                &$bytes
            ) {
                $buffer .= $data;
                $bytes += strlen($data);

                if ($uploadId === null && strlen($buffer) >= $partSize) {
                    $init = $s3->createMultipartUpload([
                        'Bucket' => $bucket,
                        'Key' => $key,
                        'ContentType' => $contentType ?: 'application/octet-stream',
                        'ACL' => 'private',
                        'Metadata' => ['OriginalName' => mb_substr($nombreOriginal, 0, 1024)],
                    ]);
                    $uploadId = (string)$init['UploadId'];
                }

                while ($uploadId !== null && strlen($buffer) >= $partSize) {
                    $partData = substr($buffer, 0, $partSize);
                    $buffer = substr($buffer, $partSize);
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
        if ($ok === false) {
            $error = curl_error($stream);
            curl_close($stream);
            if ($uploadId) {
                $this->s3->abortMultipartUpload([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                ]);
            }
            throw new RuntimeException('Error descargando URL: ' . $error);
        }
        curl_close($stream);

        if ($uploadId === null) {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $buffer,
                'ACL' => 'private',
                'ContentType' => $contentType ?: 'application/octet-stream',
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
            'source_url' => $url,
            'content_type' => $contentType,
            'bytes' => $bytes,
            'ip_origen' => (string)($req['_remote_addr'] ?? '0.0.0.0'),
            'usuario_envio' => (string)($req['_usuario'] ?? 'usuario'),
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        $repo = new FileS3Repository($this->db);
        $userId = (int)($req['_user_id'] ?? 0);
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
