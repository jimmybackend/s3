<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use ArcadeCloud\Drive\Storage\UserStoragePath;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use DateTimeInterface;
use RuntimeException;
use UploadStateStore;

require_once dirname(__DIR__, 2) . '/upload/storage/UploadStateStore.php';

/** Only controls exact multipart sessions owned by the local chunked state store. */
final class ChunkedUploadCleanupService
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private UserStoragePath $paths,
        private UploadStateStore $store
    ) {
    }

    public function run(int $days = 30, bool $execute = false): array
    {
        $cutoff = time() - max(1, min(3650, $days)) * 86400;
        $report = ['states_seen' => 0, 'states_deleted' => 0, 'multipart_aborted' => 0, 'items' => []];
        foreach ($this->store->ids() as $id) {
            $report['states_seen']++;
            try {
                $inspect = fn(): array => $this->inspect($id, $cutoff, $execute);
                $item = $execute ? $this->store->withLock($id, $inspect, true) : $inspect();
                $item ??= ['action' => 'skip_busy'];
            } catch (\Throwable $error) {
                // Do not print SDK messages: they can contain signed URLs or private keys/routes.
                $item = ['action' => 'skip_error'];
            }
            $item['state'] = $id;
            $report['states_deleted'] += (int)($item['state_deleted'] ?? false);
            $report['multipart_aborted'] += (int)($item['multipart_aborted'] ?? false);
            $report['items'][] = $item;
        }
        return $report;
    }

    private function inspect(string $id, int $cutoff, bool $execute): array
    {
        $state = $this->store->load($id);
        if (!$this->validState($id, $state)) return ['action' => 'skip_invalid'];
        $lastActivity = max((int)$state['created'], (int)($state['updated'] ?? 0), $this->store->modifiedAt($id));
        if ($lastActivity >= $cutoff) return ['action' => 'skip_recent'];

        $request = ['Bucket' => $this->bucket, 'Key' => $state['key'], 'UploadId' => $state['uploadId']];
        try {
            $marker = 0;
            do {
                $parts = $this->s3->listParts($request + ['PartNumberMarker' => $marker]);
                foreach (($parts['Parts'] ?? []) as $part) {
                    $modified = $part['LastModified'] ?? null;
                    $timestamp = $modified instanceof DateTimeInterface ? $modified->getTimestamp() : strtotime((string)$modified);
                    // Missing timestamps cannot establish that the upload is abandoned.
                    if (!$timestamp || $timestamp >= $cutoff) return ['action' => 'skip_recent_parts'];
                }
                $truncated = (bool)($parts['IsTruncated'] ?? false);
                $next = (int)($parts['NextPartNumberMarker'] ?? 0);
                if ($truncated && $next <= $marker) throw new RuntimeException('Paginación multipart inválida.');
                $marker = $next;
            } while ($truncated);
        } catch (AwsException $error) {
            if ($error->getAwsErrorCode() !== 'NoSuchUpload') throw $error;
            // It may already be complete: remove ONLY local metadata, never an S3 object.
            if ($execute) $this->store->delete($id);
            return ['action' => $execute ? 'delete_completed_or_missing_state' : 'would_delete_missing_state', 'state_deleted' => $execute];
        }

        if (!$execute) return ['action' => 'would_abort_exact_multipart'];
        try {
            $this->s3->abortMultipartUpload($request);
        } catch (AwsException $error) {
            if ($error->getAwsErrorCode() !== 'NoSuchUpload') throw $error;
            $this->store->delete($id);
            return ['action' => 'delete_completed_or_missing_state', 'state_deleted' => true];
        }
        $this->store->delete($id);
        return ['action' => 'abort_exact_multipart', 'state_deleted' => true, 'multipart_aborted' => true];
    }

    private function validState(string $id, ?array $state): bool
    {
        if (!$state || preg_match('/\A[a-f0-9]{40}\z/', $id) !== 1 || ($state['stateId'] ?? null) !== $id) return false;
        $userId = (int)($state['user_id'] ?? 0);
        $key = $state['key'] ?? null;
        $uploadId = $state['uploadId'] ?? null;
        if ($userId <= 0 || (int)($state['created'] ?? 0) <= 0 || !is_string($key) || !is_string($uploadId) || $uploadId === '') return false;
        if (preg_match('~[\x00-\x1f\\\\]|(?:^|/)\.{1,2}(?:/|$)~', $key) === 1) return false;
        return str_starts_with($key, $this->paths->rootForUser($userId)) && !str_ends_with($key, '/');
    }
}
