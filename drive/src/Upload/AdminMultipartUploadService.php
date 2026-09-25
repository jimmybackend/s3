<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use ArcadeCloud\Drive\Security\UserDirectoryRepository;
use ArcadeCloud\Drive\Storage\UserStorageProvisioner;
use Aws\S3\S3Client;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

final class AdminMultipartUploadService
{
    public function __construct(
        private UserDirectoryRepository $users,
        private UserStorageProvisioner $provisioner,
        private UploadCatalogRepository $catalog,
        private S3Client $s3,
        private string $bucket,
        private string $stateDir
    ) {
    }

    public function users(): array
    {
        return $this->users->all();
    }

    public function targetUser(int $userId): array
    {
        return $this->users->requireById($userId);
    }

    public function handle(
        int $actorUserId,
        int $targetUserId,
        string $action,
        array $post,
        array $files
    ): array {
        $targetUser = $this->targetUser($targetUserId);
        $service = $this->multipartFor($targetUserId);

        $result = match ($action) {
            'init' => $service->init($post),
            'sign' => $service->sign($post),
            'part' => $service->part($post, $files),
            'resume' => $service->resume($post),
            'complete' => $service->complete($post),
            default => throw new RuntimeException('Acción no válida.'),
        };

        if ($action === 'complete') {
            $this->registerCompleted(
                $result,
                $post,
                $actorUserId,
                $targetUserId,
                (string)$targetUser['email']
            );
        }

        return $result;
    }

    private function multipartFor(int $targetUserId): PublicMultipartUploadService
    {
        $root = $this->provisioner->ensureRoot($targetUserId);
        $prefix = rtrim($root, '/') . '/uploads';

        return new PublicMultipartUploadService(
            $this->s3,
            $this->bucket,
            $this->stateDir,
            $prefix
        );
    }

    private function registerCompleted(
        array &$result,
        array $post,
        int $actorUserId,
        int $targetUserId,
        string $targetEmail
    ): void {
        $key = trim((string)($result['key'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('S3 no devolvió la key final.');
        }

        $head = $this->s3->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        $size = (int)($head['ContentLength'] ?? 0);
        $visibleName = basename(trim((string)($post['filename'] ?? basename($key))));
        $physicalName = basename($key);
        $dir = dirname($key);
        $route = $dir === '.' ? '' : rtrim($dir, '/') . '/';
        $uploadedAt = gmdate('Y-m-d H:i:s');

        $lastModified = $head['LastModified'] ?? null;
        if ($lastModified instanceof DateTimeInterface) {
            $uploadedAt = DateTimeImmutable::createFromInterface($lastModified)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        $sha256 = $this->hashObject($key, $size);
        if ($this->catalog->isContentBlocked($sha256)) {
            try {
                $this->s3->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                ]);
            } catch (\Throwable $cleanupError) {
                throw new RuntimeException(
                    'El contenido está bloqueado y no pudo limpiarse el objeto S3 temporal.',
                    0,
                    $cleanupError
                );
            }
            throw new RuntimeException(
                'Este contenido está bloqueado por moderación y no puede volver a subirse.'
            );
        }

        $metadata = json_encode([
            'source' => 'up.php',
            'uploaded_by_user_id' => $actorUserId,
            'uploaded_at_utc' => $uploadedAt,
            'hash_sha256' => $sha256,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($metadata === false) {
            throw new RuntimeException('No se pudieron construir los metadatos de la subida.');
        }

        if ($route !== '') {
            $folderName = basename(rtrim($route, '/'));
            $parentDir = dirname(rtrim($route, '/'));
            $parentPrefix = $parentDir === '.'
                ? null
                : rtrim($parentDir, '/') . '/';

            $this->catalog->ensureFolder(
                $targetUserId,
                $route,
                $folderName,
                $parentPrefix
            );
        }

        $this->catalog->upsertCompletedMultipart(
            $targetUserId,
            $visibleName,
            $physicalName,
            $size,
            $metadata,
            $route,
            $uploadedAt
        );

        $result['registered'] = true;
        $result['registered_at_utc'] = $uploadedAt;
        $result['registered_route'] = $route;
        $result['target_user_id'] = $targetUserId;
        $result['target_email'] = $targetEmail;
    }

    private function hashObject(string $key, int $expectedBytes): string
    {
        try {
            $result = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No se pudo leer el multipart completado para verificar moderación.',
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
                    throw new RuntimeException('No se pudo completar SHA-256 del multipart.');
                }
                $bytes += strlen($chunk);
                if ($expectedBytes > 0 && $bytes > $expectedBytes) {
                    throw new RuntimeException('El multipart excedió el tamaño esperado durante SHA-256.');
                }
                hash_update($hash, $chunk);
            }
        } finally {
            if (method_exists($body, 'close')) $body->close();
        }

        if ($expectedBytes > 0 && $bytes !== $expectedBytes) {
            throw new RuntimeException('El tamaño del multipart cambió durante SHA-256.');
        }

        return hash_final($hash);
    }

    }
}
