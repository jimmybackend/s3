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
        private string $stateDir,
        private UploadTaskStore $taskStore
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

        if ($action === 'progress') {
            return $this->updateTaskProgress($targetUserId, $post);
        }
        if ($action === 'fail') {
            return $this->markTaskFailed($targetUserId, $post);
        }

        try {
            $result = match ($action) {
                'init' => $service->init($post),
                'sign' => $service->sign($post),
                'part' => $service->part($post, $files),
                'resume' => $service->resume($post),
                'complete' => $service->complete($post),
                default => throw new RuntimeException('Acción no válida.'),
            };

            $taskId = $this->taskId($post, $result);
            if ($taskId !== '' && in_array($action, ['init', 'resume'], true)) {
                $fileName = trim((string)($post['filename'] ?? $result['filename'] ?? 'Archivo'));
                $fileSize = max(0, (int)($post['filesize'] ?? $result['filesize'] ?? 0));
                $parts = is_array($result['etags'] ?? null) ? count($result['etags']) : 0;
                $chunkSize = max(0, (int)($result['chunk_size'] ?? $post['chunk_size'] ?? 0));
                $uploaded = $chunkSize > 0 ? min($fileSize, $parts * $chunkSize) : 0;
                $progress = $fileSize > 0 ? min(99, (int)floor(($uploaded / $fileSize) * 100)) : 0;
                $this->taskStore->put($targetUserId, $taskId, [
                    'status' => 'running',
                    'progress' => $progress,
                    'title' => $fileName !== '' ? $fileName : 'Archivo',
                    'detail' => $action === 'resume'
                        ? 'Subida pública reanudada desde otro navegador.'
                        : 'Subida pública directa a Amazon S3.',
                    'source' => 'UP.php',
                    'upload_mode' => 'public_multipart',
                    'destination' => 'uploads/',
                    'bytes_total' => $fileSize,
                    'bytes_uploaded' => $uploaded,
                    'parts_total' => $fileSize > 0 && $chunkSize > 0 ? (int)ceil($fileSize / $chunkSize) : 0,
                    'part_number' => $parts,
                    'service' => 'Amazon S3',
                    'provider' => 'ArcadeCloud',
                ]);
                $result['task_id'] = $taskId;
            }

            if ($action === 'complete') {
                $this->registerCompleted(
                    $result,
                    $post,
                    $actorUserId,
                    $targetUserId,
                    (string)$targetUser['email']
                );
                if ($taskId !== '') {
                    $bytes = max(0, (int)($post['filesize'] ?? 0));
                    $this->taskStore->put($targetUserId, $taskId, [
                        'status' => 'completed',
                        'progress' => 100,
                        'detail' => 'Subida pública terminada y registrada en Mi Drive.',
                        'bytes_total' => $bytes,
                        'bytes_uploaded' => $bytes,
                        'eta_seconds' => 0,
                    ]);
                    $result['task_id'] = $taskId;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $taskId = $this->taskId($post);
            if ($taskId !== '' && in_array($action, ['complete'], true)) {
                try {
                    $this->taskStore->put($targetUserId, $taskId, [
                        'status' => 'failed',
                        'progress' => 100,
                        'detail' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }

    private function updateTaskProgress(int $targetUserId, array $post): array
    {
        $taskId = $this->taskId($post);
        if ($taskId === '') throw new RuntimeException('Falta el identificador de la tarea pública.');
        $status = strtolower(trim((string)($post['status'] ?? 'running')));
        if (!in_array($status, ['queued','pending','running'], true)) $status = 'running';
        $this->taskStore->put($targetUserId, $taskId, [
            'status' => $status,
            'progress' => isset($post['progress']) ? (int)$post['progress'] : null,
            'detail' => (string)($post['detail'] ?? 'Subida pública en progreso.'),
            'bytes_total' => (int)($post['bytes_total'] ?? 0),
            'bytes_uploaded' => (int)($post['bytes_uploaded'] ?? 0),
            'parts_total' => (int)($post['parts_total'] ?? 0),
            'part_number' => (int)($post['part_number'] ?? 0),
        ]);
        return ['ok' => true, 'task_id' => $taskId];
    }

    private function markTaskFailed(int $targetUserId, array $post): array
    {
        $taskId = $this->taskId($post);
        if ($taskId === '') throw new RuntimeException('Falta el identificador de la tarea pública.');
        $this->taskStore->put($targetUserId, $taskId, [
            'status' => 'failed',
            'progress' => 100,
            'detail' => (string)($post['detail'] ?? 'La subida pública falló.'),
        ]);
        return ['ok' => true, 'task_id' => $taskId];
    }

    private function taskId(array $input, array $result = []): string
    {
        $explicit = trim((string)($input['task_id'] ?? $result['task_id'] ?? ''));
        if ($explicit !== '') return $explicit;

        $uploadId = trim((string)($input['uploadId'] ?? $result['uploadId'] ?? ''));
        $key = trim((string)($input['key'] ?? $result['key'] ?? ''));
        if ($uploadId === '' || $key === '') return '';

        return 'upload-public:' . substr(hash('sha256', $uploadId . '|' . $key), 0, 32);
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
                'Este contenido está bloqueado por moderación y no puede volver a subirse.',
                409
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
