<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\View\FileViewHelper;

final class FederationContentFingerprintService
{
    private FederatedResourceRepository $resources;

    public function __construct(private DriveApplication $app)
    {
        $this->resources = new FederatedResourceRepository($app->db());
    }

    public function ensureForFile(array $file): string
    {
        $existing = $this->resources->contentId($file);
        if ($existing !== null) return $existing;

        $key = ltrim(str_replace('\\', '/', trim($this->resources->storageKey($file))), '/');
        if ($key === '') {
            throw new FederationException('El archivo local no tiene una key S3 válida para calcular SHA-256.', 409);
        }

        $expectedBytes = max(0, (int)($file['Tamano'] ?? 0));
        try {
            $result = $this->app->s3()->getObject([
                'Bucket' => $this->app->bucket(),
                'Key' => $key,
            ]);
        } catch (\Throwable) {
            throw new FederationException('No se pudo leer el archivo original para calcular su huella SHA-256.', 409);
        }

        $body = $result['Body'] ?? null;
        if (!is_object($body) || !method_exists($body, 'read') || !method_exists($body, 'eof')) {
            throw new FederationException('S3 no devolvió un stream verificable para calcular SHA-256.', 500);
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(1048576);
                if (!is_string($chunk) || $chunk === '') {
                    if ($body->eof()) break;
                    throw new FederationException('No se pudo completar la lectura del archivo para SHA-256.', 500);
                }
                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            if (method_exists($body, 'close')) $body->close();
        }

        if ($expectedBytes > 0 && $bytes !== $expectedBytes) {
            throw new FederationException(
                'El tamaño del archivo cambió durante el cálculo SHA-256; no se bloqueará una huella dudosa.',
                409
            );
        }

        $hex = hash_final($hash);
        $contentId = 'sha256:' . $hex;
        $this->persistFileMetadata($file, $hex);

        return $contentId;
    }

    private function persistFileMetadata(array $file, string $sha256): void
    {
        $fileId = (int)($file['id_'] ?? 0);
        $userId = (int)($file['user_id_'] ?? 0);
        if ($fileId <= 0 || $userId <= 0) return;

        $metadata = FileViewHelper::metadataArray($file['Metadatos'] ?? null);
        $metadata['hash_sha256'] = strtolower($sha256);
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            throw new FederationException('No se pudo serializar la huella SHA-256 del archivo.', 500);
        }

        $stmt = $this->app->db()->prepare(
            'UPDATE FileS3 SET Metadatos=? WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1'
        );
        if (!$stmt) {
            throw new FederationException('No se pudo preparar la persistencia de SHA-256 en FileS3.', 500);
        }
        $stmt->bind_param('sii', $encoded, $fileId, $userId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo guardar SHA-256 en FileS3: ' . $message, 500);
        }
        $stmt->close();
    }
}
