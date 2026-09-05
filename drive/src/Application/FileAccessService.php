<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Http\ByteRange;
use Aws\S3\S3Client;
use RuntimeException;

final class FileAccessService
{
    public function __construct(
        private FileRecordLocator $locator,
        private S3Client $s3,
        private string $bucket
    ) {
    }

    public function locate(int $userId, string $key): array
    {
        return $this->locator->requireReadableByKey($userId, $this->normalizeKey($key));
    }

    public function signedDownload(int $userId, string $key): array
    {
        $row = $this->locate($userId, $key);
        $realKey = (string)$row['_key'];
        $name = trim((string)($row['Nombre'] ?? '')) ?: basename($realKey);
        $name = str_replace(["\r", "\n", '"'], ['', '', "'"], $name);

        $command = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $realKey,
            'ResponseContentDisposition' => 'attachment; filename="' . $name . '"',
        ]);
        $request = $this->s3->createPresignedRequest($command, '+10 minutes');

        return [
            'id' => (int)$row['id_'],
            'nombre' => $name,
            'key_s3' => $realKey,
            'url_descarga' => (string)$request->getUri(),
        ];
    }

    public function downloadStream(int $userId, string $key, string $fallbackName = ''): array
    {
        $row = $this->locate($userId, $key);
        $realKey = (string)$row['_key'];
        $head = $this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$realKey]);
        $object = $this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$realKey]);
        return [
            'row'=>$row,
            'key'=>$realKey,
            'name'=>$this->downloadName($row, $realKey, $fallbackName),
            'mime'=>trim((string)($head['ContentType'] ?? '')) ?: 'application/octet-stream',
            'length'=>isset($head['ContentLength']) ? (int)$head['ContentLength'] : null,
            'body'=>$object['Body'],
        ];
    }

    public function inlineRange(int $userId, string $key, ?string $rangeHeader, int $maxBytes = 1048576): array
    {
        $row = $this->locate($userId, $key);
        $realKey = (string)$row['_key'];
        $head = $this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$realKey]);
        $size = (int)($head['ContentLength'] ?? 0);
        $range = ByteRange::parse($rangeHeader, $size, $maxBytes);
        if ($range === null) {
            throw new RuntimeException('Rango no válido', 416);
        }
        $object = $this->s3->getObject([
            'Bucket'=>$this->bucket,'Key'=>$realKey,
            'Range'=>'bytes='.$range->start.'-'.$range->end,
        ]);
        return [
            'row'=>$row,'key'=>$realKey,'size'=>$size,'range'=>$range,
            'mime'=>trim((string)($head['ContentType'] ?? '')) ?: 'application/octet-stream',
            'etag'=>trim((string)($head['ETag'] ?? '')),
            'last_modified'=>$head['LastModified'] ?? null,
            'body'=>$object['Body'],
        ];
    }

    public function outputName(array $row): string
    {
        return $this->sanitizeName((string)($row['Nombre'] ?? 'archivo'));
    }

    private function downloadName(array $row, string $realKey, string $fallback): string
    {
        $visible = trim((string)($row['Nombre'] ?? '')) ?: trim($fallback) ?: basename($realKey);
        $physicalExt = strtolower((string)pathinfo($realKey, PATHINFO_EXTENSION));
        $base = preg_replace('/\.[^.]+$/', '', $visible) ?: $visible;
        return $this->sanitizeName($base . ($physicalExt !== '' ? '.'.$physicalExt : ''));
    }

    private function sanitizeName(string $name): string
    {
        $name = preg_replace('/[\/\\\\]/', '-', $name) ?? $name;
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? $name;
        $name = str_replace(['\r','\n','"'], ['', '', "'"], trim($name));
        return $name !== '' ? $name : 'archivo';
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }
}
