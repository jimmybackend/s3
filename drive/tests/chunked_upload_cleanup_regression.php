<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Storage/UserStoragePath.php';
require_once dirname(__DIR__) . '/src/Storage/StorageObjectNameCodec.php';
require_once dirname(__DIR__) . '/src/Upload/ChunkedUploadCleanupService.php';
require_once dirname(__DIR__) . '/upload/drivers/Chunked15MBUploader.php';

use ArcadeCloud\Drive\Storage\UserStoragePath;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use ArcadeCloud\Drive\Upload\ChunkedUploadCleanupService;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;

final class Config { public const RUTA_RAIZ = 'Data/'; }
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}

$directory = sys_get_temp_dir() . '/arcade-cleanup-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$store = new UploadStateStore($directory);
$id = sha1('fixture');
$old = time() - 40 * 86400;
$base = ['stateId' => $id, 'user_id' => 2, 'created' => $old, 'key' => 'Data2/d_physical/f_report.docx', 'uploadId' => 'test-multipart'];
$seed = static function (array $overrides = []) use ($directory, $id, $base, $old): void {
    file_put_contents("$directory/$id.json", json_encode(array_replace($base, $overrides), JSON_THROW_ON_ERROR));
    touch("$directory/$id.json", $old);
};
$calls = [];
$mode = 'old';
$s3 = new S3Client([
    'version' => 'latest', 'region' => 'us-east-1',
    'credentials' => ['key' => 'fixture', 'secret' => 'fixture'],
    'handler' => static function (CommandInterface $command) use (&$calls, &$mode, $old) {
        $name = $command->getName();
        $calls[] = [$name, $command->toArray()];
        if (!in_array($name, ['ListParts', 'AbortMultipartUpload'], true)) {
            throw new RuntimeException('Unexpected S3 mutation: ' . $name);
        }
        if ($name === 'ListParts') {
            if (in_array($mode, ['missing', 'denied'], true)) {
                return new RejectedPromise(new AwsException('fixture', $command, ['code' => $mode === 'missing' ? 'NoSuchUpload' : 'AccessDenied']));
            }
            $marker = (int)($command['PartNumberMarker'] ?? 0);
            $paged = $mode === 'paged' && $marker === 0;
            $modified = ($mode === 'recent' || ($mode === 'paged' && $marker > 0)) ? time() : $old;
            $result = ['Parts' => [['PartNumber' => 1, 'LastModified' => new DateTimeImmutable('@' . $modified)]], 'IsTruncated' => $paged];
            if ($paged) $result['NextPartNumberMarker'] = 1;
            return new FulfilledPromise(new Result($result));
        }
        if ($mode === 'abort_error') return new RejectedPromise(new AwsException('fixture', $command, ['code' => 'AccessDenied']));
        return new FulfilledPromise(new Result([]));
    },
]);
$cleanup = new ChunkedUploadCleanupService($s3, 'fixture-bucket', new UserStoragePath(), $store);
$action = static fn(array $result): string => $result['items'][0]['action'];
try {
    $seed();
    $before = scandir($directory);
    check($action($cleanup->run()) === 'would_abort_exact_multipart', 'dry-run inspects the exact multipart');
    check(scandir($directory) === $before && $store->load($id) === $base, 'dry-run does not change files or create locks');
    check(array_column($calls, 0) === ['ListParts'], 'dry-run never aborts');

    $calls = [];
    $result = $cleanup->run(30, true);
    check($result['multipart_aborted'] === 1 && $store->load($id) === null, 'stale multipart outside uploads/ is aborted and state removed');
    check($calls[1][1]['Bucket'] === 'fixture-bucket' && $calls[1][1]['Key'] === $base['key'] && $calls[1][1]['UploadId'] === $base['uploadId'], 'abort preserves exact bucket, physical key and upload ID');

    foreach (['recent' => 'skip_recent_parts', 'paged' => 'skip_recent_parts', 'denied' => 'skip_error', 'abort_error' => 'skip_error'] as $mode => $expected) {
        $seed(); $calls = [];
        check($action($cleanup->run(30, true)) === $expected && $store->load($id) !== null, "$mode preserves state");
        if ($mode !== 'abort_error') check(!in_array('AbortMultipartUpload', array_column($calls, 0), true), "$mode does not abort");
    }
    $mode = 'missing'; $seed(); $calls = [];
    check($action($cleanup->run(30, true)) === 'delete_completed_or_missing_state', 'NoSuchUpload removes only local state');
    check(array_column($calls, 0) === ['ListParts'], 'completed object is never deleted');

    $mode = 'old'; $seed(['updated' => time()]); $calls = [];
    check($action($cleanup->run(30, true)) === 'skip_recent' && $calls === [], 'recent heartbeat protects old creation date');
    $seed(); touch("$directory/$id.json");
    check($action($cleanup->run(30, true)) === 'skip_recent', 'legacy mtime also protects active states');

    foreach (['Data3/foreign', 'Data2/../foreign', 'Data2/path/'] as $key) {
        $seed(['key' => $key]); $calls = [];
        check($action($cleanup->run(30, true)) === 'skip_invalid' && $calls === [], 'invalid ownership/path never reaches S3');
    }
    $seed();
    $otherStore = new UploadStateStore($directory);
    $otherStore->withLock($id, static function () use ($cleanup, $action): void {
        check($action($cleanup->run(30, true)) === 'skip_busy', 'cleanup skips a lease held by another store');
    });

    $seed();
    $uploader = new Chunked15MBUploader(new mysqli(), $s3, 'fixture-bucket', new StorageObjectNameCodec(), $store);
    $part = $uploader->part(['stateId' => $id, 'uploadId' => $base['uploadId'], 'key' => $base['key'], '_user_id' => 2, 'partNumber' => 1, 'contentLength' => 10]);
    check($part['ok'] && (int)$store->load($id)['updated'] >= time() - 2, 'signing refreshes activity without a network request');
    check($action($cleanup->run(30, true)) === 'skip_recent', 'new signed part protects an old multipart');
    try {
        $uploader->part(['stateId' => $id, '_user_id' => 3]);
        throw new RuntimeException('Foreign user was accepted');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'ajeno'), 'uploader preserves ownership validation');
    }
    check(!in_array('DeleteObject', array_column($calls, 0), true), 'no cleanup case deletes an S3 object');
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    foreach (glob($directory . '/.lock-*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
