<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sync;

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use Aws\S3\S3Client;
use RuntimeException;

final class S3SyncService
{
    private const MAX_KEYS_PER_BATCH = 10;

    public function __construct(
        private SyncRepository $repository,
        private S3Client $s3,
        private string $bucket,
        private UserStoragePath $paths,
        private StorageObjectNameCodec $names
    ) {
    }

    public function synchronizeBatch(
        int $userId,
        ?string $syncId = null,
        ?string $continuationToken = null,
        ?string $scopePrefix = null
    ): array {
        $root = $this->paths->rootForUser($userId);
        $base = $scopePrefix === null || trim($scopePrefix) === ''
            ? $root
            : $this->paths->normalizeForUser($scopePrefix, $userId);

        $firstBatch = $syncId === null || $syncId === '';

        if ($firstBatch) {
            $syncId = bin2hex(random_bytes(16));

            $this->s3->headBucket([
                'Bucket' => $this->bucket
            ]);

            $this->repository->cleanupExpiredSeen($userId);
        } elseif (!preg_match('/^[a-f0-9]{32}$/', $syncId)) {
            throw new RuntimeException('Identificador de sincronización inválido.');
        }

        $params = [
            'Bucket' => $this->bucket,
            'Prefix' => $base,
            'MaxKeys' => self::MAX_KEYS_PER_BATCH
        ];

        if ($continuationToken !== null && $continuationToken !== '') {
            $params['ContinuationToken'] = $continuationToken;
        }

        $this->repository->begin();

        try {
            $folders = [];
            $files = 0;

            if ($firstBatch) {
                $this->addFolder($folders, $base);
            }

            $result = $this->s3->listObjectsV2($params);

            foreach ((array)($result['Contents'] ?? []) as $object) {
                $key = (string)($object['Key'] ?? '');

                if ($key === '') {
                    continue;
                }

                if (str_ends_with($key, '/')) {
                    $this->addFolder($folders, $key);
                    continue;
                }

                $this->addParentFolders($folders, $key, $base);

                $visible = $this->repository->existingVisibleName($userId, $key);

                if ($visible === null) {
                    $visible = $this->recoverVisibleFileName($key);
                }

                $this->repository->upsertFile(
                    $userId,
                    $key,
                    (int)($object['Size'] ?? 0),
                    $visible
                );

                $this->repository->markSeen(
                    $syncId,
                    $userId,
                    'file',
                    $key
                );

                $files++;
            }

            foreach ($folders as $prefix => $info) {
                $this->repository->upsertFolder(
                    $userId,
                    $prefix,
                    $info['name'],
                    $info['parent']
                );

                $this->repository->markSeen(
                    $syncId,
                    $userId,
                    'folder',
                    $prefix
                );
            }

            $done = empty($result['IsTruncated']);

            $removed = [
                'files_removed' => 0,
                'folders_removed' => 0
            ];

            if ($done) {
                $removed = $base === $root
                    ? $this->repository->finalizeSync($userId, $syncId)
                    : $this->repository->finalizeSyncPrefix($userId, $syncId, $base);
            }

            $this->repository->commit();

            return [
                'ok' => true,
                'user_id' => $userId,
                'bucket' => $this->bucket,
                'base' => $base,
                'scope' => $base === $root ? 'user' : 'folder',
                'sync_id' => $syncId,
                'done' => $done,
                'next_token' => $done
                    ? null
                    : (string)($result['NextContinuationToken'] ?? ''),
                'batch_files' => $files,
                'batch_folders' => count($folders),
                'files_removed' => $removed['files_removed'],
                'folders_removed' => $removed['folders_removed']
            ];

        } catch (\Throwable $error) {
            $this->repository->rollback();
            throw $error;
        }
    }

    public function synchronize(
        int $userId,
        ?string $scopePrefix = null
    ): array {
        $syncId = null;
        $token = null;
        $files = 0;
        $folders = 0;
        $last = [];

        do {
            $last = $this->synchronizeBatch(
                $userId,
                $syncId,
                $token,
                $scopePrefix
            );

            $syncId = $last['sync_id'];
            $token = $last['next_token'];
            $files += (int)$last['batch_files'];
            $folders += (int)$last['batch_folders'];

        } while (empty($last['done']));

        $last['files_upserted'] = $files;
        $last['folders_upserted'] = $folders;

        return $last;
    }

    private function recoverVisibleFileName(string $key): string
    {
        $base = basename($key);
        $decoded = $this->names->recoverFileVisibleName($base);

        if ($decoded !== null) {
            return $decoded;
        }

        return $base;
    }

    private function addParentFolders(
        array &$folders,
        string $key,
        string $floorPrefix
    ): void {
        $dir = dirname($key);

        if ($dir === '.' || $dir === '') {
            return;
        }

        $floorPrefix = rtrim($floorPrefix, '/') . '/';
        $acc = '';

        foreach (explode('/', $dir) as $part) {
            if ($part === '') {
                continue;
            }

            $acc .= $part . '/';

            if (strpos($acc, $floorPrefix) !== 0) {
                continue;
            }

            $this->addFolder($folders, $acc);
        }
    }

    private function addFolder(array &$folders, string $prefix): void
    {
        $prefix = rtrim($prefix, '/') . '/';

        if ($prefix === './') {
            return;
        }

        $trim = rtrim($prefix, '/');
        $pos = strrpos($trim, '/');

        $physical = $pos === false
            ? $trim
            : substr($trim, $pos + 1);

        $name = $this->names->recoverFolderVisibleName($physical) ?? $physical;

        $parent = $pos === false
            ? null
            : rtrim(substr($trim, 0, $pos + 1), '/') . '/';

        if ($parent === '/' || $parent === '') {
            $parent = null;
        }

        $folders[$prefix] = [
            'name' => $name !== '' ? $name : $prefix,
            'parent' => $parent
        ];
    }
}
