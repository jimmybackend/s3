<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Storage\FileRecordRepository;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;
use RuntimeException;

final class FileMutationService
{
    private StorageObjectNameCodec $codec;

    public function __construct(
        private FileRecordRepository $files,
        private S3Client $s3,
        private string $bucket,
        ?StorageObjectNameCodec $codec = null
    ) {
        $this->codec = $codec ?? new StorageObjectNameCodec();
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
            $this->files->move($userId, (int)$file['id_'], $newRoute, $newKey);
        } catch (\Throwable $error) {
            try {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $newKey]);
            } catch (\Throwable) {
            }
            throw $error;
        }

        // FileS3 ya apunta al objeto nuevo antes de retirar el anterior. Si S3
        // rechaza el delete puede quedar un objeto huérfano recuperable, nunca
        // una fila que apunte a bytes que ya fueron eliminados.
        $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $oldKey]);

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

    public function copy(int $userId, int|string $ref, string $newRoute): array
    {
        $file = $this->files->requireByRef($userId, $ref, true);
        $oldKey = (string)$file['_key'];
        $newRoute = $this->files->normalizePrefix($newRoute);
        $visibleName = $this->uniqueCopyName(
            $userId,
            $newRoute,
            trim((string)($file['Nombre'] ?? '')) ?: basename($oldKey)
        );
        $physicalName = $this->codec->createFileObjectName($visibleName);
        $newKey = $this->files->normalizeKey($newRoute . $physicalName);

        $this->s3->copyObject([
            'Bucket' => $this->bucket,
            'CopySource' => $this->bucket . '/' . $oldKey,
            'Key' => $newKey,
            'ACL' => 'private',
            'MetadataDirective' => 'COPY',
        ]);

        try {
            $newId = $this->files->duplicateFrom(
                $userId,
                (int)$file['id_'],
                $visibleName,
                $newRoute,
                $newKey
            );
        } catch (\Throwable $error) {
            try {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $newKey]);
            } catch (\Throwable) {
            }
            throw $error;
        }

        return [
            'id' => $newId,
            'source_id' => (int)$file['id_'],
            'nombre' => $visibleName,
            'ruta_nueva' => $newRoute,
            'key_s3' => $newKey,
            'old_key' => $oldKey,
            'estado' => 'copiado',
        ];
    }

    public function copyMany(int $userId, array $refs, string $newRoute): array
    {
        if ($refs === []) throw new RuntimeException('No hay archivos seleccionados.');
        $newRoute = $this->files->normalizePrefix($newRoute);
        $total = 0;
        foreach ($refs as $ref) {
            $this->copy($userId, is_int($ref) ? $ref : (string)$ref, $newRoute);
            $total++;
        }
        return ['total' => $total, 'ruta_nueva' => $newRoute, 'estado' => 'copiados'];
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

    private function uniqueCopyName(int $userId, string $route, string $name): string
    {
        if (!$this->files->visibleNameExists($userId, $route, $name)) {
            return $name;
        }

        $dot = strrpos($name, '.');
        $stem = $dot !== false && $dot > 0 ? substr($name, 0, $dot) : $name;
        $extension = $dot !== false && $dot > 0 ? substr($name, $dot) : '';

        for ($number = 1; $number <= 1000; $number++) {
            $suffix = $number === 1 ? ' - copia' : ' - copia ' . $number;
            $candidate = $stem . $suffix . $extension;
            if (!$this->files->visibleNameExists($userId, $route, $candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No se pudo generar un nombre disponible para la copia.');
    }
}
