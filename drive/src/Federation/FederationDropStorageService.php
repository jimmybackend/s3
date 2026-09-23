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
            'ACL' => 'private',
            'Metadata' => ['arcadecloud-drop' => '1'],
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

    public function presignedDownload(string $key, string $filename): string
    {
        $filename = str_replace(["\r", "\n", '"'], ['', '', "'"], basename($filename));
        if ($filename === '') $filename = 'archivo';
        $command = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ResponseContentDisposition' => 'attachment; filename="' . $filename . '"',
        ]);
        $request = $this->s3->createPresignedRequest($command, '+10 minutes');
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
