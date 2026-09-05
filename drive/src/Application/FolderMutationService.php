<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\FolderMutationRepository;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use Aws\S3\S3Client;
use RuntimeException;

final class FolderMutationService
{
    public function __construct(
        private FolderMutationRepository $folders,
        private UserStoragePath $paths,
        private StorageObjectNameCodec $codec,
        private S3Client $s3,
        private string $bucket
    ) {
    }

    public function create(int $userId, string $route, string $name): array
    {
        $base = $this->paths->rootForUser($userId);
        $route = $this->paths->normalizeForUser($route, $userId);
        $name = $this->validateName($name);
        if ($this->folders->visibleNameExists($userId, $route, $name)) {
            throw new RuntimeException('Ya existe una carpeta visible con ese nombre en este nivel.');
        }

        $physical = $this->codec->createFolderObjectName($name);
        $prefix = $this->normalizePrefix($route . $physical);
        if (!str_starts_with($prefix, $base)) throw new RuntimeException('Ruta fuera de la carpeta base del usuario.');

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $prefix,
            'Body' => '',
            'ACL' => 'private',
            'ContentType' => 'application/x-directory',
        ]);

        try {
            $this->folders->upsert($userId, $prefix, $name, $route);
        } catch (\Throwable $error) {
            try {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $prefix]);
            } catch (\Throwable) {
            }
            throw $error;
        }

        return ['ruta' => $prefix, 'nombre' => $name];
    }

    public function rename(int $userId, string $route, string $name): void
    {
        $base = $this->paths->rootForUser($userId);
        $route = $this->paths->normalizeForUser($route, $userId);
        if ($route === $base) throw new RuntimeException('No puedes renombrar la carpeta raíz del usuario.');
        $name = $this->validateName($name);
        $folder = $this->folders->requireActive($userId, $route);
        $parent = trim((string)($folder['ParentPrefix'] ?? '')) ?: $base;
        $parent = $this->paths->normalizeForUser($parent, $userId);
        if ($this->folders->visibleNameExists($userId, $parent, $name, $route)) {
            throw new RuntimeException('Ya existe una carpeta visible con ese nombre en este nivel.');
        }
        $this->folders->renameVisible($userId, (int)$folder['id_'], $name);
    }

    public function move(int $userId, string $origin, string $destination): array
    {
        $base = $this->paths->rootForUser($userId);
        $origin = $this->paths->normalizeForUser($origin, $userId);
        $destination = $this->paths->normalizeForUser($destination, $userId);
        if ($origin === $base) throw new RuntimeException('No puedes mover la carpeta raíz.');

        $final = $this->normalizePrefix($destination . basename(rtrim($origin, '/')));
        if ($origin === $final) throw new RuntimeException('El destino es igual al origen.');
        if (str_starts_with($final, $origin)) throw new RuntimeException('No puedes mover una carpeta dentro de sí misma.');

        $probe = $this->s3->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $final,
            'MaxKeys' => 1,
        ]);
        if (!empty($probe['KeyCount']) || $this->folders->exists($userId, $final)) {
            throw new RuntimeException('La carpeta destino ya existe.');
        }

        $continuation = null;
        $toDelete = [];
        do {
            $params = ['Bucket' => $this->bucket, 'Prefix' => $origin, 'MaxKeys' => 1000];
            if ($continuation) $params['ContinuationToken'] = $continuation;
            $objects = $this->s3->listObjectsV2($params);
            foreach (($objects['Contents'] ?? []) as $object) {
                $oldKey = (string)$object['Key'];
                $newKey = $final . substr($oldKey, strlen($origin));
                $this->s3->copyObject([
                    'Bucket' => $this->bucket,
                    'CopySource' => rawurlencode($this->bucket . '/' . $oldKey),
                    'Key' => $newKey,
                    'ACL' => 'private',
                    'MetadataDirective' => 'COPY',
                ]);
                $toDelete[] = ['Key' => $oldKey];
                if (count($toDelete) >= 1000) {
                    $this->deleteObjects($toDelete);
                    $toDelete = [];
                }
            }
            $continuation = !empty($objects['IsTruncated']) ? ($objects['NextContinuationToken'] ?? null) : null;
        } while ($continuation);
        if ($toDelete !== []) $this->deleteObjects($toDelete);

        $updated = $this->folders->moveTree($userId, $origin, $final);
        return ['origen' => $origin, 'destino' => $final] + $updated;
    }

    public function delete(int $userId, string $route): array
    {
        $base = $this->paths->rootForUser($userId);
        $route = $this->paths->normalizeForUser($route, $userId);
        if ($route === $base) throw new RuntimeException('No puedes eliminar la carpeta raíz del usuario.');
        $this->folders->requireActive($userId, $route);

        $deletedS3 = 0;
        $continuation = null;
        do {
            $params = ['Bucket' => $this->bucket, 'Prefix' => $route, 'MaxKeys' => 1000];
            if ($continuation) $params['ContinuationToken'] = $continuation;
            $result = $this->s3->listObjectsV2($params);
            $objects = [];
            foreach (($result['Contents'] ?? []) as $object) {
                if (!empty($object['Key'])) $objects[] = ['Key' => (string)$object['Key']];
            }
            if ($objects !== []) {
                $this->deleteObjects($objects);
                $deletedS3 += count($objects);
            }
            $continuation = !empty($result['IsTruncated']) ? ($result['NextContinuationToken'] ?? null) : null;
        } while ($continuation);

        $deleted = $this->folders->deleteTree($userId, $route);
        return [
            'estado' => 'ok',
            'prefix_eliminado' => $route,
            's3_objects' => $deletedS3,
            'files_deleted' => $deleted['files'],
            'folders_deleted' => $deleted['folders'],
            'parent' => $this->parentPrefix($route) ?: $base,
        ];
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, '\\/:*?"<>|') !== false || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new RuntimeException('El nombre contiene caracteres no permitidos: \\ / : * ? " < > |');
        }
        return $name;
    }

    private function deleteObjects(array $objects): void
    {
        $this->s3->deleteObjects([
            'Bucket' => $this->bucket,
            'Delete' => ['Objects' => $objects, 'Quiet' => true],
        ]);
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = str_replace('\\', '/', trim($prefix));
        $prefix = preg_replace('~/+~', '/', $prefix) ?? $prefix;
        return rtrim(ltrim($prefix, '/'), '/') . '/';
    }

    private function parentPrefix(string $prefix): ?string
    {
        $prefix = rtrim($this->normalizePrefix($prefix), '/');
        $pos = strrpos($prefix, '/');
        return $pos === false ? null : substr($prefix, 0, $pos + 1);
    }
}
