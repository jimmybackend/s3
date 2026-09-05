<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;
use RuntimeException;

final class SingleUploadService
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private UploadCatalogRepository $catalog,
        private StorageObjectNameCodec $codec
    ) {
    }

    public function upload(
        string $tmpPath,
        string $originalName,
        string $route,
        int $userId,
        string $mimeType,
        int $fileSize,
        string $remoteAddr = 'unknown',
        string $userAgent = 'unknown'
    ): array {
        $originalName = trim($originalName);
        if ($originalName === '') {
            throw new RuntimeException('El nombre original del archivo es obligatorio.');
        }
        if ($userId <= 0 || $tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('Datos de subida inválidos.');
        }

        $route = $this->normalizePrefix($route);
        $physicalName = $this->codec->createFileObjectName($originalName);
        $key = $this->normalizeKey($route . $physicalName);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $tmpPath,
            'ContentType' => $mimeType,
            'ACL' => 'private',
        ]);

        $metadata = json_encode([
            'ip_origen' => $remoteAddr,
            'user_agent' => $userAgent,
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
            'hash_sha256' => @hash_file('sha256', $tmpPath) ?: null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $id = $this->catalog->insert([
                'Nombre' => $originalName,
                'Encriptado' => $key,
                'Tamano' => $fileSize,
                'Metadatos' => $metadata,
                'Ruta' => $route,
                'Found' => 1,
                'AccessType' => 'normal',
                'PasswordHash' => null,
                'SecureHint' => null,
                'SecureUpdatedAt' => null,
                'Fecha' => date('Y-m-d H:i:s'),
                'user_id_' => $userId,
            ]);
        } catch (\Throwable $error) {
            try {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
            } catch (\Throwable) {
            }
            throw $error;
        }

        return [
            'id' => $id,
            'nombre_original' => $originalName,
            'nombre_encriptado' => $key,
            'ruta' => $route,
            'key_s3' => $key,
            'tamano' => $fileSize,
        ];
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = $this->normalizeKey($prefix);
        return $prefix === '' ? '' : rtrim($prefix, '/') . '/';
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }
}
