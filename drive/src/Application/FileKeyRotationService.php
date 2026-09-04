<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

final class FileKeyRotationService
{
    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private FileRecordLocator $locator,
        private StorageObjectNameCodec $names
    ) {
    }

    public function rotate(int $userId, string $requestedKey): array
    {
        $row = $this->locator->requireReadableByKey($userId, $requestedKey);
        $oldKey = (string)$row['_key'];
        $route = rtrim((string)$row['Ruta'], '/') . '/';
        $visible = trim((string)$row['Nombre']);
        if ($visible === '') {
            throw new RuntimeException('El archivo no tiene nombre visible.');
        }
        $newBase = $this->names->createFileObjectName($visible);
        $newKey = $route . $newBase;

        $this->s3->copyObject([
            'Bucket' => $this->bucket,
            'CopySource' => rawurlencode($this->bucket . '/' . $oldKey),
            'Key' => $newKey,
            'ACL' => 'private',
            'MetadataDirective' => 'COPY',
        ]);

        $id = (int)$row['id_'];
        $stmt = $this->db->prepare('UPDATE FileS3 SET Encriptado = ?, Ruta = ?, Found = 1 WHERE id_ = ? AND user_id_ = ?');
        if (!$stmt) {
            try {$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $newKey]);} catch (\Throwable) {}
            throw new RuntimeException('No se pudo preparar la rotación de key: ' . $this->db->error);
        }
        $stmt->bind_param('ssii', $newBase, $route, $id, $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            try {$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $newKey]);} catch (\Throwable) {}
            throw new RuntimeException('No se pudo actualizar FileS3: ' . $error);
        }
        $stmt->close();

        try {
            $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $oldKey]);
        } catch (\Throwable $e) {
            throw new RuntimeException('La nueva key quedó registrada, pero no se pudo borrar la key anterior: ' . $e->getMessage());
        }

        return ['estado' => 'ok', 'newKey' => $newKey, 'encriptado' => $newBase, 'nombre' => $visible];
    }
}
