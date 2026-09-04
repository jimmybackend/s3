<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Sync;

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use Aws\S3\S3Client;

final class S3SyncService
{
    public function __construct(
        private SyncRepository $repository,
        private S3Client $s3,
        private string $bucket,
        private UserStoragePath $paths,
        private StorageObjectNameCodec $names,
        private int $pageDelayUs = 150000
    ) {
    }

    public function synchronize(int $userId): array
    {
        $base = $this->paths->rootForUser($userId);
        $this->s3->headBucket(['Bucket' => $this->bucket]);
        $folders = [];
        $files = 0;
        $this->repository->begin();
        try {
            $this->repository->resetFound($userId);
            $this->addFolder($folders, $base);
            $params = ['Bucket' => $this->bucket, 'Prefix' => $base, 'MaxKeys' => 1000];
            do {
                $res = $this->s3->listObjectsV2($params);
                foreach ((array)($res['Contents'] ?? []) as $object) {
                    $key = (string)($object['Key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    if (str_ends_with($key, '/')) {
                        $this->addFolder($folders, $key);
                        continue;
                    }
                    $this->addParentFolders($folders, $key);
                    $visible = $this->recoverVisibleFileName($key);
                    $this->repository->upsertFile($userId, $key, (int)($object['Size'] ?? 0), $visible);
                    $files++;
                }
                if (!empty($res['IsTruncated']) && !empty($res['NextContinuationToken'])) {
                    $params['ContinuationToken'] = $res['NextContinuationToken'];
                } else {
                    unset($params['ContinuationToken']);
                }
                if ($this->pageDelayUs > 0) {
                    usleep($this->pageDelayUs);
                }
            } while (!empty($res['IsTruncated']));

            foreach ($folders as $prefix => $info) {
                $this->repository->upsertFolder($userId, $prefix, $info['name'], $info['parent']);
            }
            $this->repository->purgeMissing($userId);
            $this->repository->commit();
            return ['ok' => true, 'user_id' => $userId, 'bucket' => $this->bucket, 'base' => $base, 'files_upserted' => $files, 'folders_upserted' => count($folders)];
        } catch (\Throwable $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    private function recoverVisibleFileName(string $key): string
    {
        $base = basename($key);
        $decoded = $this->names->recoverFileVisibleName($base);
        if ($decoded !== null) {
            return $decoded;
        }

        // Formatos opacos históricos f_<id>... no contenían el nombre.
        // Durante la sincronización MANUAL intentamos recuperarlo de metadata.
        if ($this->names->looksLikeOpaqueLegacyFileName($base)) {
            try {
                $head = $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
                foreach ((array)($head['Metadata'] ?? []) as $metaKey => $value) {
                    $normalized = strtolower(str_replace(['_', '-'], '', (string)$metaKey));
                    if (in_array($normalized, ['originalname', 'filename'], true) && trim((string)$value) !== '') {
                        return basename(str_replace('\\', '/', (string)$value));
                    }
                }
            } catch (\Throwable) {
                // La key sigue siendo recuperable aunque el nombre histórico no lo sea.
            }
        }
        return $base;
    }

    private function addParentFolders(array &$folders, string $key): void
    {
        $dir = dirname($key);
        if ($dir === '.' || $dir === '') {
            return;
        }
        $acc = '';
        foreach (explode('/', $dir) as $part) {
            if ($part === '') {
                continue;
            }
            $acc .= $part . '/';
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
        $physical = $pos === false ? $trim : substr($trim, $pos + 1);
        $name = $this->names->recoverFolderVisibleName($physical) ?? $physical;
        $parent = $pos === false ? null : rtrim(substr($trim, 0, $pos + 1), '/') . '/';
        if ($parent === '/' || $parent === '') {
            $parent = null;
        }
        $folders[$prefix] = ['name' => $name !== '' ? $name : $prefix, 'parent' => $parent];
    }
}
