<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\FileRecordRepository;
use Aws\S3\S3Client;
use RuntimeException;

final class FileMutationService
{
    public function __construct(
        private FileRecordRepository $files,
        private S3Client $s3,
        private string $bucket
    ) {
    }

    public function delete(int $userId, int|string $ref): array
    {
        $file = $this->files->requireByRef($userId, $ref, false);
        $key = (string)$file['_key'];
        $id = (int)$file['id_'];

        $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
        $this->files->markFound($userId, $id, false);

        return ['id' => $id, 'key_s3' => $key, 'estado' => 'eliminado'];
    }

    public function deleteMany(int $userId, array $refs): array
    {
        if ($refs === []) throw new RuntimeException('No hay archivos seleccionados.');
        $total = 0;
        foreach ($refs as $ref) {
            $this->delete($userId, is_int($ref) ? $ref : (string)$ref);
            $total++;
        }
        return ['total' => $total, 'estado' => 'eliminados'];
    }

    public function move(int $userId, int|string $ref, string $newRoute): array
    {
        $file = $this->files->requireByRef($userId, $ref, true);
        $oldRoute = $this->files->normalizePrefix((string)$file['Ruta']);
        $oldKey = (string)$file['_key'];
        $physicalName = basename(str_replace('\\', '/', (string)$file['Encriptado']));
        if ($physicalName === '') throw new RuntimeException('El archivo no tiene nombre físico válido.');

        $newRoute = $this->files->normalizePrefix($newRoute);
        $newKey = $this->files->normalizeKey($newRoute . $physicalName);
        if ($oldKey === $newKey) throw new RuntimeException('El archivo ya está en esa misma ruta.');

        $this->s3->copyObject([
            'Bucket' => $this->bucket,
            'CopySource' => $this->bucket . '/' . $oldKey,
            'Key' => $newKey,
            'ACL' => 'private',
            'MetadataDirective' => 'COPY',
        ]);

        try {
            $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $oldKey]);
            $this->files->move($userId, (int)$file['id_'], $newRoute, $newKey);
        } catch (\Throwable $error) {
            try {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $newKey]);
            } catch (\Throwable) {
            }
            throw $error;
        }

        return [
            'id' => (int)$file['id_'],
            'ruta_anterior' => $oldRoute,
            'ruta_nueva' => $newRoute,
            'encriptado_nuevo' => $newKey,
            'old_key' => $oldKey,
            'key_s3' => $newKey,
        ];
    }

    public function moveMany(int $userId, array $refs, string $newRoute): array
    {
        if ($refs === []) throw new RuntimeException('No hay archivos seleccionados.');
        $newRoute = $this->files->normalizePrefix($newRoute);
        $total = 0;
        foreach ($refs as $ref) {
            $this->move($userId, is_int($ref) ? $ref : (string)$ref, $newRoute);
            $total++;
        }
        return ['total' => $total, 'ruta_nueva' => $newRoute, 'estado' => 'movidos'];
    }

    public function rename(int $userId, int|string $ref, string $newName): array
    {
        $newName = trim($newName);
        if ($newName === '') throw new RuntimeException('Debes indicar el nuevo nombre del archivo.');
        if (str_contains($newName, '/') || str_contains($newName, '\\')) {
            throw new RuntimeException('El nombre del archivo no debe contener rutas.');
        }

        $file = $this->files->requireByRef($userId, $ref, true);
        $id = (int)$file['id_'];
        $this->files->renameVisible($userId, $id, $newName);

        return [
            'id' => $id,
            'nombre_anterior' => (string)($file['Nombre'] ?? ''),
            'nombre' => $newName,
            'encriptado' => (string)($file['Encriptado'] ?? ''),
            'ruta' => (string)($file['Ruta'] ?? ''),
            'key_s3' => (string)$file['_key'],
            's3_modificado' => false,
            'encriptado_modificado' => false,
        ];
    }
}
