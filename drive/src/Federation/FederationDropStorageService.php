<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use Aws\S3\S3Client;

final class FederationDropStorageService
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private FederationDropConfig $config
    ) {
    }

    public function objectKey(string $dropId, string $filename): string
    {
        $safe = preg_replace('/[^\pL\pN._-]+/u', '-', basename($filename)) ?? 'archivo';
        $safe = trim($safe, '.-_');
        if ($safe === '') $safe = 'archivo';
        if (function_exists('mb_substr')) $safe = mb_substr($safe, 0, 120);
        else $safe = substr($safe, 0, 120);
        return 'FederationDrops/' . gmdate('Y/m') . '/' . $dropId . '/' . $safe;
    }

    public function presignedUpload(string $key, string $mimeType, int $sizeBytes): array
    {
        if ($sizeBytes <= 0 || $sizeBytes > $this->config->maxFileBytes) {
            throw new FederationException('Tamaño FederationDrop fuera del límite permitido.', 413);
        }
        $mimeType = trim($mimeType) ?: 'application/octet-stream';
        $command = $this->s3->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $mimeType,
        ]);
        $request = $this->s3->createPresignedRequest($command, '+30 minutes');
        return [
            'url' => (string)$request->getUri(),
            'headers' => ['Content-Type' => $mimeType],
            'expires_in_seconds' => 1800,
        ];
    }

    public function verifyUploaded(string $key, int $expectedBytes): array
    {
        try {
            $head = $this->s3->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (\Throwable) {
            throw new FederationException('El archivo todavía no aparece en el almacenamiento FederationDrop.', 409);
        }

        $actual = (int)($head['ContentLength'] ?? 0);
        if ($actual <= 0 || $actual !== $expectedBytes || $actual > $this->config->maxFileBytes) {
            throw new FederationException('El tamaño subido no coincide con la orden FederationDrop.', 409);
        }

        return [
            'size_bytes' => $actual,
            'etag' => trim((string)($head['ETag'] ?? ''), '"'),
            'mime_type' => trim((string)($head['ContentType'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
        ];
    }

    public function storeFromLocalFile(
        string $key,
        string $path,
        string $mimeType,
        int $expectedBytes,
        array $metadata = []
    ): array {
        if ($key === '' || !str_starts_with($key, 'FederationDrops/')) {
            throw new FederationException('Key FederationDrop inválida.', 500);
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new FederationException('Fuente temporal FederationDrop no disponible.', 500);
        }
        if ($expectedBytes <= 0 || $expectedBytes > $this->config->maxFileBytes) {
            throw new FederationException('Tamaño FederationDrop fuera del límite permitido.', 413);
        }

        $safeMetadata = [];
        foreach ($metadata as $name => $value) {
            $name = strtolower(trim((string)$name));
            $value = trim((string)$value);
            if ($name === '' || $value === '' || strlen($name) > 64 || strlen($value) > 512) continue;
            if (!preg_match('/\A[a-z0-9-]+\z/', $name)) continue;
            $safeMetadata[$name] = $value;
        }

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $path,
            'ContentType' => trim($mimeType) ?: 'application/octet-stream',
            'Metadata' => $safeMetadata,
        ]);

        return $this->verifyUploaded($key, $expectedBytes);
    }

    public function contentId(string $key, int $expectedBytes): string
    {
        if ($key === '' || !str_starts_with($key, 'FederationDrops/')) {
            throw new FederationException('Key FederationDrop inválida para huella digital.', 500);
        }
        try {
            $result = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (\Throwable) {
            throw new FederationException('No se pudo leer el FederationDrop para calcular su huella.', 409);
        }

        $body = $result['Body'] ?? null;
        if (!is_object($body) || !method_exists($body, 'read')) {
            throw new FederationException('El almacenamiento no devolvió un stream verificable.', 500);
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(1048576);
                if (!is_string($chunk) || $chunk === '') {
                    if ($body->eof()) break;
                    throw new FederationException('No se pudo completar la lectura para huella digital.', 500);
                }
                $bytes += strlen($chunk);
                if ($bytes > $expectedBytes || $bytes > $this->config->maxFileBytes) {
                    throw new FederationException('El contenido excede el tamaño autorizado durante la verificación.', 409);
                }
                hash_update($hash, $chunk);
            }
        } finally {
            if (method_exists($body, 'close')) $body->close();
        }
        if ($bytes !== $expectedBytes) {
            throw new FederationException('El tamaño cambió durante el cálculo de huella digital.', 409);
        }
        return 'sha256:' . hash_final($hash);
    }

    public function presignedDownload(string $key, string $filename): string
    {
        $filename = str_replace(["\r", "\n", '"'], ['', '', "'"], basename($filename));
        if ($filename === '') $filename = 'archivo';
        $command = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ResponseContentDisposition' => 'attachment; filename="' . $filename . '"',
        ]);
        $request = $this->s3->createPresignedRequest($command, '+2 minutes');
        return (string)$request->getUri();
    }

    public function delete(string $key): void
    {
        if ($key === '' || !str_starts_with($key, 'FederationDrops/')) {
            throw new FederationException('Key FederationDrop inválida.', 500);
        }
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
    }
}
