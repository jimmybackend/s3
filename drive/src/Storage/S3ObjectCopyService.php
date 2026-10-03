<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Storage;

use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartCopy;
use Aws\S3\S3Client;

/** Server-side copying; no object bytes pass through PHP memory or disk. */
final class S3ObjectCopyService
{
    public function __construct(private S3Client $s3, private string $bucket) {}

    public function copy(string $sourceKey, string $destinationKey): void
    {
        $source = $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $sourceKey]);
        if ((int)$source['ContentLength'] <= 5 * 1024 * 1024 * 1024) {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $destinationKey,
                'CopySource' => rawurlencode($this->bucket . '/' . $sourceKey),
                'ACL' => 'private',
                'MetadataDirective' => 'COPY',
            ];
            if (!empty($source['ETag'])) $params['CopySourceIfMatch'] = (string)$source['ETag'];
            $this->s3->copyObject($params);
            return;
        }

        $copy = new MultipartCopy($this->s3, ['source_bucket' => $this->bucket, 'source_key' => $sourceKey], [
            'bucket' => $this->bucket,
            'key' => $destinationKey,
            'acl' => 'private',
            'source_metadata' => $source,
            'concurrency' => 2,
            'part_size' => 512 * 1024 * 1024,
        ]);
        try {
            $copy->copy();
        } catch (MultipartUploadException $error) {
            $upload = $error->getState()->getId();
            if (!empty($upload['UploadId'])) {
                try { $this->s3->abortMultipartUpload($upload); } catch (\Throwable) {}
            }
            throw $error;
        }
    }
}
