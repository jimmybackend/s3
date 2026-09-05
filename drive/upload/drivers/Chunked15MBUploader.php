<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';
require_once __DIR__ . '/../storage/UploadStateStore.php';

final class Chunked15MBUploader implements UploaderInterface
{
    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private StorageObjectNameCodec $codec,
        private UploadStateStore $store
    ) {
    }

    private function signature(string $filename, int $filesize, string $route, int $userId): string
    {
        return sha1($userId . '|' . $route . '|' . $filename . '|' . $filesize);
    }

    public function init(array $req): array
    {
        $filename = trim((string)($req['filename'] ?? ''));
        $filesize = (int)($req['filesize'] ?? 0);
        $mime = trim((string)($req['mime'] ?? 'application/octet-stream'));

        if ($filename === '' || $filesize <= 0) {
            throw new RuntimeException('Datos inválidos: filename/filesize');
        }

        $userId = (int)($req['_user_id'] ?? 0);
        $carpeta = trim((string)($req['ruta_objetivo'] ?? ''), '/');
        if ($userId <= 0 || $carpeta === '') {
            throw new RuntimeException('Usuario/ruta objetivo inválidos');
        }

        $signature = $this->signature($filename, $filesize, $carpeta, $userId);
        $physicalName = $this->codec->createFileObjectName($filename);
        $key = $carpeta . '/' . $physicalName;

        $result = $this->s3->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $mime,
            'ACL' => 'private',
            'Metadata' => [
                'original-name' => mb_substr($filename, 0, 1024),
                'original-size' => (string)$filesize,
            ],
        ]);

        $uploadId = (string)$result->get('UploadId');
        $stateId = $signature;

        $this->store->save($stateId, [
            'stateId' => $stateId,
            'uploadId' => $uploadId,
            'key' => $key,
            'filename' => $filename,
            'filesize' => $filesize,
            'mime' => $mime,
            'parts' => [],
            'user_id' => $userId,
            'ruta_objetivo' => rtrim($carpeta, '/') . '/',
            'created' => time(),
        ]);

        return [
            'ok' => true,
            'stateId' => $stateId,
            'uploadId' => $uploadId,
            'key' => $key,
        ];
    }

    public function part(array $req): array
    {
        $step = (string)($req['step'] ?? 'sign');

        if ($step === 'resume') {
            $stateId = (string)($req['stateId'] ?? '');
            $uploadId = (string)($req['uploadId'] ?? '');
            $key = (string)($req['key'] ?? '');

            $meta = $stateId !== '' ? $this->store->load($stateId) : null;
            if ($meta && (int)($meta['user_id'] ?? 0) !== (int)($req['_user_id'] ?? 0)) {
                throw new RuntimeException('La subida multipart no pertenece al usuario actual.');
            }
            if ($meta) {
                $uploadId = (string)$meta['uploadId'];
                $key = (string)$meta['key'];
            }

            if ($uploadId === '' || $key === '') {
                throw new RuntimeException('Faltan parámetros para resume (stateId o uploadId+key)');
            }

            $etags = $this->listPartsEtags($uploadId, $key);
            if ($meta && $stateId !== '') {
                $meta['parts'] = $etags;
                $this->store->save($stateId, $meta);
            }

            return [
                'ok' => true,
                'etags' => $etags,
                'uploadId' => $uploadId,
                'key' => $key,
            ];
        }

        $stateId = (string)($req['stateId'] ?? '');
        $uploadId = (string)($req['uploadId'] ?? '');
        $key = (string)($req['key'] ?? '');
        $partNumber = (int)($req['partNumber'] ?? 0);
        $contentLength = (int)($req['contentLength'] ?? 0);

        $meta = $stateId !== '' ? $this->store->load($stateId) : null;
        $currentUserId = (int)($req['_user_id'] ?? 0);
        if (!$meta || (int)($meta['user_id'] ?? 0) !== $currentUserId) {
            throw new RuntimeException('Estado multipart inválido o ajeno al usuario actual.');
        }
        if ((string)($meta['uploadId'] ?? '') !== $uploadId || (string)($meta['key'] ?? '') !== $key) {
            throw new RuntimeException('La parte no coincide con la subida multipart iniciada.');
        }

        if ($uploadId === '' || $key === '' || $partNumber <= 0 || $contentLength <= 0) {
            throw new RuntimeException('Parámetros inválidos para firmar (uploadId,key,partNumber,contentLength)');
        }

        $command = $this->s3->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
            'ContentLength' => $contentLength,
        ]);

        $presigned = $this->s3->createPresignedRequest($command, '+1 hour');

        return ['ok' => true, 'url' => (string)$presigned->getUri()];
    }

    public function complete(array $req): array
    {
        $stateId = (string)($req['stateId'] ?? '');
        $uploadId = (string)($req['uploadId'] ?? '');
        $key = (string)($req['key'] ?? '');

        $meta = $stateId !== '' ? $this->store->load($stateId) : null;
        if ($meta && (int)($meta['user_id'] ?? 0) !== (int)($req['_user_id'] ?? 0)) {
            throw new RuntimeException('La subida multipart no pertenece al usuario actual.');
        }
        if ($meta) {
            $uploadId = (string)$meta['uploadId'];
            $key = (string)$meta['key'];
        }

        $etagsJson = (string)($req['etags'] ?? '');
        $etags = $etagsJson !== '' ? (json_decode($etagsJson, true) ?: []) : [];
        if (empty($etags) && $uploadId !== '' && $key !== '') {
            $etags = $this->listPartsEtags($uploadId, $key);
        }

        if ($uploadId === '' || $key === '' || empty($etags)) {
            throw new RuntimeException('Faltan parámetros para complete (uploadId,key,etags)');
        }

        $parts = [];
        foreach ($etags as $number => $tag) {
            $parts[] = [
                'PartNumber' => (int)$number,
                'ETag' => '"' . trim((string)$tag, '"') . '"',
            ];
        }
        usort($parts, static fn(array $a, array $b): int => (int)$a['PartNumber'] <=> (int)$b['PartNumber']);

        $this->s3->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        $carpeta = dirname($key) . '/';
        $nombreEncriptado = basename($key);
        $nombreOriginal = (string)($meta['filename'] ?? 'archivo');
        $filesize = (int)($meta['filesize'] ?? 0);
        $userId = (int)($req['_user_id'] ?? 0);

        $metadatos = json_encode([
            'multipart' => true,
            'uploadId' => $uploadId,
            'parts' => array_keys($etags),
            'ip_origen' => (string)($req['_remote_addr'] ?? '0.0.0.0'),
            'usuario' => (string)($req['_usuario'] ?? 'usuario'),
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        $repo = new FileS3Repository($this->db);
        $fileId = $repo->insertFile([
            'Nombre' => $nombreOriginal,
            'Encriptado' => $nombreEncriptado,
            'Tamano' => $filesize,
            'Metadatos' => $metadatos,
            'Ruta' => $carpeta,
            'Found' => 1,
            'AccessType' => 'normal',
            'Fecha' => date('Y-m-d H:i:s'),
            'user_id_' => $userId,
        ]);

        if ($stateId !== '') {
            $this->store->delete($stateId);
        }

        return [
            'ok' => true,
            'key' => $key,
            'file_id' => $fileId,
            'ruta' => $carpeta,
            'encriptado' => $nombreEncriptado,
        ];
    }

    private function listPartsEtags(string $uploadId, string $key): array
    {
        $out = [];
        $marker = null;

        do {
            $args = [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ];
            if ($marker !== null) {
                $args['PartNumberMarker'] = $marker;
            }

            $result = $this->s3->listParts($args);
            $parts = $result->get('Parts') ?: [];

            foreach ($parts as $part) {
                $number = (int)($part['PartNumber'] ?? 0);
                $etag = trim((string)($part['ETag'] ?? ''), '"');
                if ($number > 0 && $etag !== '') {
                    $out[(string)$number] = $etag;
                }
            }

            $isTruncated = (bool)($result->get('IsTruncated') ?? false);
            $marker = $result->get('NextPartNumberMarker') ?? null;
        } while ($isTruncated);

        return $out;
    }
}
