<?php
declare(strict_types=1);

use Aws\S3\S3Client;

final class BlockedUploadException extends RuntimeException
{
}

final class ModerationUploadGuard
{
    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket
    ) {
    }

    public function isBlocked(string $sha256): bool
    {
        $sha256 = $this->normalizeSha256($sha256);
        $contentId = 'sha256:' . $sha256;

        $stmt = $this->db->prepare(
            "SELECT 1 FROM FederationModerationBlocks WHERE ContentId=? AND Status='active' LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo consultar la lista de contenido bloqueado: ' . $this->db->error
            );
        }

        $stmt->bind_param('s', $contentId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo consultar la lista de contenido bloqueado: ' . $error);
        }

        $stmt->store_result();
        $blocked = $stmt->num_rows > 0;
        $stmt->close();
        return $blocked;
    }

    public function assertSha256Allowed(string $sha256): string
    {
        $sha256 = $this->normalizeSha256($sha256);
        if ($this->isBlocked($sha256)) {
            throw new BlockedUploadException(
                'Este contenido está bloqueado por moderación y no puede volver a subirse.'
            );
        }
        return $sha256;
    }

    public function hashObject(string $key, int $expectedBytes = 0): string
    {
        $key = ltrim(str_replace('\\', '/', trim($key)), '/');
        if ($key === '') {
            throw new RuntimeException('No se puede verificar una key S3 vacía.');
        }

        try {
            $result = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No se pudo leer el objeto S3 para verificar moderación.',
                0,
                $e
            );
        }

        $body = $result['Body'] ?? null;
        if (!is_object($body) || !method_exists($body, 'read') || !method_exists($body, 'eof')) {
            throw new RuntimeException('S3 no devolvió un stream verificable para moderación.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (!$body->eof()) {
                $chunk = $body->read(1048576);
                if (!is_string($chunk) || $chunk === '') {
                    if ($body->eof()) break;
                    throw new RuntimeException('No se pudo completar el cálculo SHA-256 del objeto S3.');
                }

                $bytes += strlen($chunk);
                if ($expectedBytes > 0 && $bytes > $expectedBytes) {
                    throw new RuntimeException(
                        'El objeto S3 excedió el tamaño esperado durante la verificación de moderación.'
                    );
                }

                hash_update($hash, $chunk);
            }
        } finally {
            if (method_exists($body, 'close')) {
                $body->close();
            }
        }

        if ($expectedBytes > 0 && $bytes !== $expectedBytes) {
            throw new RuntimeException(
                'El tamaño del objeto S3 cambió durante la verificación de moderación.'
            );
        }

        return hash_final($hash);
    }

    public function verifyObjectAllowed(
        string $key,
        int $expectedBytes = 0,
        bool $deleteIfBlocked = true
    ): string {
        $sha256 = $this->hashObject($key, $expectedBytes);

        if (!$this->isBlocked($sha256)) {
            return $sha256;
        }

        if ($deleteIfBlocked) {
            try {
                $this->s3->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                ]);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'El contenido está bloqueado y no pudo limpiarse el objeto S3 temporal.',
                    0,
                    $e
                );
            }
        }

        throw new BlockedUploadException(
            'Este contenido está bloqueado por moderación y no puede volver a subirse.'
        );
    }

    public function mergeHashIntoMetadata(?string $json, string $sha256): string
    {
        $sha256 = $this->normalizeSha256($sha256);
        $metadata = [];

        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $metadata['hash_sha256'] = $sha256;
        $encoded = json_encode(
            $metadata,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('No se pudo guardar la huella SHA-256 en los metadatos.');
        }

        return $encoded;
    }

    private function normalizeSha256(string $sha256): string
    {
        $sha256 = strtolower(trim($sha256));
        if (str_starts_with($sha256, 'sha256:')) {
            $sha256 = substr($sha256, 7);
        }

        if (!preg_match('/\A[a-f0-9]{64}\z/', $sha256)) {
            throw new RuntimeException('Huella SHA-256 de subida inválida.');
        }

        return $sha256;
    }
}
