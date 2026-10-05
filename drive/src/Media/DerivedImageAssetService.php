<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use ArcadeCloud\Drive\Storage\S3ObjectCopyService;
use Aws\S3\S3Client;

/**
 * Keeps cached image derivatives under thumbs/ aligned with the physical S3 key.
 * Failures here must never make the original file unavailable.
 */
final class DerivedImageAssetService
{
    public function __construct(private S3Client $s3, private string $bucket)
    {
    }

    /** @return array{listed:int,copied:int,deleted:int} */
    public function transfer(string $sourceKey, string $destinationKey, bool $deleteSource): array
    {
        $sourcePrefix = $this->derivativePrefix($sourceKey);
        $destinationPrefix = $this->derivativePrefix($destinationKey);
        if ($sourcePrefix === $destinationPrefix) {
            return ['listed' => 0, 'copied' => 0, 'deleted' => 0];
        }

        $listed = 0;
        $copied = 0;
        $delete = [];
        $continuation = null;
        $copyService = new S3ObjectCopyService($this->s3, $this->bucket);

        do {
            $params = ['Bucket' => $this->bucket, 'Prefix' => $sourcePrefix, 'MaxKeys' => 1000];
            if ($continuation) $params['ContinuationToken'] = $continuation;
            $result = $this->s3->listObjectsV2($params);
            $listed++;

            foreach (($result['Contents'] ?? []) as $object) {
                $oldDerivative = (string)($object['Key'] ?? '');
                if ($oldDerivative === '' || !str_starts_with($oldDerivative, $sourcePrefix)) continue;
                $newDerivative = $destinationPrefix . substr($oldDerivative, strlen($sourcePrefix));
                $copyService->copy($oldDerivative, $newDerivative);
                $copied++;
                if ($deleteSource) $delete[] = ['Key' => $oldDerivative];
            }

            $continuation = !empty($result['IsTruncated'])
                ? ($result['NextContinuationToken'] ?? null)
                : null;
        } while ($continuation);

        $deleted = 0;
        if ($deleteSource) {
            foreach (array_chunk($delete, 1000) as $chunk) {
                if ($chunk === []) continue;
                $result = $this->s3->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => $chunk, 'Quiet' => true],
                ]);
                if (empty($result['Errors'])) $deleted += count($chunk);
            }
        }

        return ['listed' => $listed, 'copied' => $copied, 'deleted' => $deleted];
    }

    public function derivativePrefix(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', trim($key)), '/');
        $root = 'Data/';
        $rest = $key;
        if (preg_match('~^(Data\\d*/)(.*)$~i', $key, $match)) {
            $root = $match[1];
            $rest = $match[2];
        }
        $withoutExtension = preg_replace('/\\.[A-Za-z0-9]+$/', '', $rest) ?? $rest;
        return 'thumbs/' . $root . $withoutExtension . '__';
    }
}
