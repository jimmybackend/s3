<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use ArcadeCloud\Drive\Security\UserDirectoryRepository;
use ArcadeCloud\Drive\Storage\UserStorageProvisioner;
use Aws\S3\S3Client;
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
        $metadata = json_encode([
            'source' => 'up.php',
            'uploaded_by_user_id' => $actorUserId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($metadata === false) {
            throw new RuntimeException('No se pudieron construir los metadatos de la subida.');
        }

        $this->catalog->upsertCompletedMultipart(
            $targetUserId,
            $visibleName,
            $physicalName,
            $size,
            $metadata,
            $route
        );

        $result['registered'] = true;
        $result['target_user_id'] = $targetUserId;
        $result['target_email'] = $targetEmail;
    }
}
