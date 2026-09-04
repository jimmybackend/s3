<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use Aws\S3\S3Client;
use RuntimeException;

final class PublicMultipartUploadService
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $stateDir,
        private string $prefix = 'Data/uploads'
    ) {
        $this->stateDir = rtrim($this->stateDir, '/\\');
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0770, true) && !is_dir($this->stateDir)) {
            throw new RuntimeException('No se pudo crear el directorio privado de estado de subidas públicas.');
        }
    }

    public function init(array $input): array
    {
        $filename = trim((string)($input['filename'] ?? ''));
        $filesize = (int)($input['filesize'] ?? 0);
        $mime = trim((string)($input['mime'] ?? 'application/octet-stream'));

        $chunkSize = (int)($input['chunk_size'] ?? (15 * 1024 * 1024));

        $minChunk = 5 * 1024 * 1024;
        $maxChunk = 24 * 1024 * 1024;

        if ($filename === '' || $filesize <= 0) {
            throw new RuntimeException('Datos de archivo inválidos.');
        }

        if ($chunkSize < $minChunk || $chunkSize > $maxChunk) {
            throw new RuntimeException('Tamaño de paquete inválido.');
        }

        $sig = $this->signature($filename, $filesize);
        $basename = preg_replace('/[^\w\-.]+/u', '_', basename($filename)) ?: 'archivo';
        $key = $this->prefix . '/' . gmdate('Ymd') . '/' . $sig . '-' . $basename;

        $res = $this->s3->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $mime,
            'ACL' => 'private',
            'Metadata' => ['original-name' => $filename, 'original-size' => (string)$filesize],
        ]);
        $uploadId = (string)$res->get('UploadId');

        $this->save($sig, [
            'filename' => $filename,
            'filesize' => $filesize,
            'key' => $key,
            'uploadId' => $uploadId,
            'parts' => [],
            'chunk_size' => $chunkSize,
            'created' => time(),
        ]);

        return [
            'ok' => true,
            'uploadId' => $uploadId,
            'key' => $key,
            'signature' => $sig,
            'chunk_size' => $chunkSize,
        ];
    }

    public function sign(array $input): array
    {
        $uploadId = trim((string)($input['uploadId'] ?? ''));
        $key = trim((string)($input['key'] ?? ''));
        $partNumber = (int)($input['partNumber'] ?? 0);
        $this->assertKey($key);
        if ($uploadId === '' || $partNumber <= 0) {
            throw new RuntimeException('Parámetros inválidos para firmar.');
        }

        $cmd = $this->s3->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);
        $request = $this->s3->createPresignedRequest($cmd, '+1 hour');
        return ['ok' => true, 'url' => (string)$request->getUri()];
    }

    public function part(array $input, array $files): array
    {
        $uploadId = trim((string)($input['uploadId'] ?? ''));
        $key = trim((string)($input['key'] ?? ''));
        $partNumber = (int)($input['partNumber'] ?? 0);
        $this->assertKey($key);
        if ($uploadId === '' || $partNumber <= 0 || empty($files['part']['tmp_name']) || !is_uploaded_file($files['part']['tmp_name'])) {
            throw new RuntimeException('Paquete inválido.');
        }

        $tmp = (string)$files['part']['tmp_name'];
        $size = (int)($files['part']['size'] ?? 0);
        if ($size <= 0) throw new RuntimeException('Paquete vacío.');
        $fh = fopen($tmp, 'rb');
        if (!$fh) throw new RuntimeException('No se pudo abrir el paquete temporal.');
        try {
            $res = $this->s3->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $fh,
                'ContentLength' => $size,
            ]);
        } finally {
            fclose($fh);
        }
        $etag = trim((string)$res->get('ETag'), '"');
        if ($etag === '') throw new RuntimeException('S3 no devolvió ETag del paquete.');
        return ['ok' => true, 'partNumber' => $partNumber, 'etag' => $etag, 'size' => $size];
    }

    public function resume(array $input): array
    {
        $filename = trim((string)($input['filename'] ?? ''));
        $filesize = (int)($input['filesize'] ?? 0);
        $uploadId = trim((string)($input['uploadId'] ?? ''));
        $key = trim((string)($input['key'] ?? ''));

        if ($filename !== '' && $filesize > 0) {
            $sig = $this->signature($filename, $filesize);
            $meta = $this->load($sig);
            if ($meta) {
                $remote = $this->listParts((string)$meta['uploadId'], (string)$meta['key']);
                $meta['parts'] = $remote + (is_array($meta['parts'] ?? null) ? $meta['parts'] : []);
                $this->save($sig, $meta);
                return [
                    'found' => true,
                    'uploadId' => $meta['uploadId'],
                    'key' => $meta['key'],
                    'etags' => $meta['parts'],
                    'chunk_size' => (int)($meta['chunk_size'] ?? (15 * 1024 * 1024)),
                ];
            }
        }

        if ($uploadId !== '' && $key !== '') {
            $this->assertKey($key);
            $etags = $this->listParts($uploadId, $key);
            if ($etags) return ['found' => true, 'uploadId' => $uploadId, 'key' => $key, 'etags' => $etags];
        }
        return ['found' => false];
    }

    public function complete(array $input): array
    {
        $uploadId = trim((string)($input['uploadId'] ?? ''));
        $key = trim((string)($input['key'] ?? ''));
        $this->assertKey($key);
        $etags = json_decode((string)($input['etags'] ?? '{}'), true) ?: [];
        if ($uploadId === '' || !$etags) throw new RuntimeException('Faltan parámetros para completar.');

        $parts = [];
        foreach ($etags as $num => $tag) {
            $parts[] = ['PartNumber' => (int)$num, 'ETag' => '"' . trim((string)$tag, '"') . '"'];
        }
        usort($parts, static fn(array $a, array $b): int => ((int)$a['PartNumber']) <=> ((int)$b['PartNumber']));

        $res = $this->s3->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        $cmd = $this->s3->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);
        $request = $this->s3->createPresignedRequest($cmd, '+1 hour');
        $this->deleteStateFor($uploadId, $key);

        return [
            'ok' => true,
            'location' => (string)($res->get('Location') ?? ''),
            'objectUrl' => 's3://' . $this->bucket . '/' . $key,
            'key' => $key,
            'url' => (string)$request->getUri(),
        ];
    }

    private function signature(string $filename, int $filesize): string
    {
        return sha1(
            rtrim($this->prefix, '/') . '|' .
            $filename . '|' .
            $filesize
        );
    }

    private function assertKey(string $key): void
    {
        $prefix = rtrim($this->prefix, '/') . '/';
        if ($key === '' || strpos($key, $prefix) !== 0 || str_contains($key, '..')) {
            throw new RuntimeException('Key pública inválida.');
        }
    }

    private function statePath(string $sig): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . preg_replace('/[^a-f0-9]/i', '_', $sig) . '.json';
    }

    private function save(string $sig, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($this->statePath($sig), $json, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar el estado de la subida.');
        }
    }

    private function load(string $sig): ?array
    {
        $path = $this->statePath($sig);
        if (!is_file($path)) return null;
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function listParts(string $uploadId, string $key): array
    {
        $this->assertKey($key);
        $out = [];
        $marker = null;
        do {
            $args = ['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId];
            if ($marker !== null) $args['PartNumberMarker'] = $marker;
            $res = $this->s3->listParts($args);
            foreach (($res->get('Parts') ?: []) as $part) {
                $num = (int)($part['PartNumber'] ?? 0);
                $etag = trim((string)($part['ETag'] ?? ''), '"');
                if ($num > 0 && $etag !== '') $out[(string)$num] = $etag;
            }
            $truncated = (bool)($res->get('IsTruncated') ?? false);
            $marker = $res->get('NextPartNumberMarker') ?? null;
        } while ($truncated);
        return $out;
    }

    private function deleteStateFor(string $uploadId, string $key): void
    {
        foreach (glob($this->stateDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (is_array($data) && ($data['uploadId'] ?? '') === $uploadId && ($data['key'] ?? '') === $key) {
                @unlink($file);
            }
        }
    }
}
