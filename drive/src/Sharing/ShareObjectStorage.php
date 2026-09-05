<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sharing;

use Aws\S3\S3Client;

final class ShareObjectStorage
{
    public function __construct(
        private S3Client $s3,
        private string $bucket
    ) {
    }

    public function presignedUrl(string $key, string $ttl = '+10 minutes'): string
    {
        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        $request = $this->s3->createPresignedRequest($cmd, $ttl);
        return (string)$request->getUri();
    }

    public function read(string $key): string
    {
        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        return (string)$result['Body'];
    }
}
