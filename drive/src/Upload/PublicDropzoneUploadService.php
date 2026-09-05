<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;
use RuntimeException;

final class PublicDropzoneUploadService
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $basePrefix,
        private StorageObjectNameCodec $codec,
        private UploadCatalogRepository $repository
    ) {
        $this->basePrefix = $this->normalizeBasePrefix($this->basePrefix);
    }

    public function upload(array $file, string $requestedPrefix, array $requestMeta, int $userId): array
    {
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $nameOrig = basename((string)($file['name'] ?? ''));

        if ($nameOrig === '' || $tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('Archivo temporal inválido.');
        }

        $size = (int)(filesize($tmpPath) ?: 0);
        if ($size <= 0) {
            throw new RuntimeException('El archivo temporal no existe o está vacío.');
        }

        $prefix = $this->normalizePrefix($requestedPrefix);
        $physicalName = $this->codec->createFileObjectName($nameOrig);
        $key = $prefix . $physicalName;

        $metadata = [
            'ip_origen' => $this->safe((string)($requestMeta['remote_addr'] ?? 'desconocido')),
            'user_agent' => $this->safe((string)($requestMeta['user_agent'] ?? 'desconocido')),
            'host_remoto' => $this->safe((string)($requestMeta['remote_host'] ?? 'desconocido')),
            'idioma' => $this->safe((string)($requestMeta['accept_language'] ?? 'desconocido')),
            'referer' => $this->safe((string)($requestMeta['referer'] ?? 'ninguno')),
            'conexion' => $this->safe((string)($requestMeta['connection'] ?? 'desconocido')),
            'puerto_remoto' => $this->safe((string)($requestMeta['remote_port'] ?? 'desconocido')),
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
            'tamano_kb' => round($size / 1024, 2),
            'hash_sha256' => (string)(hash_file('sha256', $tmpPath) ?: ''),
            'ruta_s3' => $key,
        ];

        $contentType = (string)(mime_content_type($tmpPath) ?: 'application/octet-stream');

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $tmpPath,
            'ACL' => 'private',
            'ContentType' => $contentType,
        ]);

        $fileId = $this->repository->insert([
            'Nombre' => $nameOrig,
            'Encriptado' => $physicalName,
            'Tamano' => $size,
            'Metadatos' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Ruta' => $prefix,
            'Found' => 1,
            'AccessType' => 'normal',
            'Fecha' => date('Y-m-d H:i:s'),
            'user_id_' => max(0, $userId),
        ]);

        return [
            'estado' => 'ok',
            'mensaje' => 'Archivo subido y registrado correctamente.',
            'nombre_original' => $nameOrig,
            'nombre_encriptado' => $physicalName,
            'ruta_s3' => $key,
            'id_registro' => $fileId,
        ];
    }

    private function normalizeBasePrefix(string $prefix): string
    {
        $prefix = str_replace('\\', '/', trim($prefix));
        $prefix = preg_replace('~/+~', '/', $prefix) ?? $prefix;
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            throw new RuntimeException('La raíz compartida no está configurada.');
        }
        return $prefix . '/';
    }

    private function normalizePrefix(string $requested): string
    {
        $requested = str_replace('\\', '/', trim($requested));
        $requested = preg_replace('~/+~', '/', $requested) ?? $requested;
        $requested = ltrim($requested, '/');

        if ($requested === '') {
            return $this->basePrefix;
        }

        if (str_contains($requested, '..')) {
            throw new RuntimeException('Ruta compartida inválida.');
        }

        $requested = rtrim($requested, '/') . '/';
        if (strpos($requested, $this->basePrefix) !== 0) {
            throw new RuntimeException('La ruta solicitada está fuera de la zona compartida.');
        }

        return $requested;
    }

    private function safe(string $value): string
    {
        return substr($value, 0, 255);
    }
}
