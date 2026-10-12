<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use Aws\S3\S3Client;
use mysqli;
use Throwable;

/**
 * Conservative reconciliation: never creates or deletes S3/FileS3 objects.
 * Success requires the server-issued key to exist in S3 AND in FileS3 for
 * the same owner, with matching size. Unverified jobs remain recoverable.
 */
final class UploadTaskReconciler
{
    public function __construct(
        private UploadTaskStore $store,
        private mysqli $db,
        private S3Client $s3,
        private string $bucket
    ) {}

    public function run(int $limit = 20): array
    {
        $stats = ['checked' => 0, 'completed' => 0, 'unverified' => 0, 'errors' => 0];
        foreach ($this->store->staleLocalUploads(180, $limit) as $job) {
            ++$stats['checked'];
            $key = (string)$job['object_key'];
            $user = (int)$job['user_id'];
            $id = (string)$job['id'];
            try {
                $stmt = $this->db->prepare(
                    'SELECT id_, Tamano FROM FileS3 WHERE user_id_ = ? AND Encriptado = ? LIMIT 1'
                );
                if (!$stmt) throw new \RuntimeException('FileS3 query could not be prepared');
                try {
                    $stmt->bind_param('is', $user, $key);
                    $stmt->execute();
                    $stmt->bind_result($fileId, $fileSize);
                    $found = $stmt->fetch();
                } finally {
                    $stmt->close();
                }

                // An S3 object without the matching FileS3 row is NOT enough.
                // This also prevents completing unfinished or blocked uploads.
                if (!$found || (int)$fileId <= 0) {
                    ++$stats['unverified'];
                    continue;
                }
                $head = $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
                $actual = (int)($head['ContentLength'] ?? -1);
                $registered = (int)$fileSize;
                $expected = (int)($job['bytes_total'] ?? 0);
                if ($actual < 0 || $actual !== $registered
                    || ($expected > 0 && $actual !== $expected)) {
                    ++$stats['unverified'];
                    continue;
                }
                $saved = $this->store->put($user, $id, [
                    'status' => 'completed',
                    'progress' => 100,
                    'detail' => 'Archivo confirmado en Amazon S3 y FileS3 por reconciliación.',
                    'bytes_total' => $actual,
                    'bytes_uploaded' => $actual,
                    'eta_seconds' => 0,
                ]);
                if (($saved['status'] ?? '') === 'completed') ++$stats['completed'];
            } catch (Throwable $e) {
                ++$stats['errors'];
                error_log('[ArcadeCloud upload reconcile] ' . $e::class . ': ' . $e->getMessage());
            }
        }
        return $stats;
    }
}
