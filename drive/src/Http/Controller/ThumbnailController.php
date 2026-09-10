<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class ThumbnailController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function show(): never
    {
        $session = $this->app->session();
        $session->start();

        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            header('X-Thumb-Status: NO_SESSION');
            $this->fallback();
        }

        $key = $this->request->queryString('key');
        if ($key === '') {
            header('X-Thumb-Status: NO_KEY');
            $this->fallback();
        }

        $width = max(24, min(512, (int)$this->request->queryString('w', '96')));
        $height = max(24, min(512, (int)$this->request->queryString('h', '96')));
        $fit = strtolower($this->request->queryString('fit', 'cover')) === 'contain' ? 'contain' : 'cover';

        $userId = $session->userId();
        $sessionSnapshot = $session->snapshot();
        $session->closeWrite();
        $started = microtime(true);

        try {
            $thumbnail = $this->app->thumbnailService()->get($userId, $key, $width, $height, $fit, $sessionSnapshot);
            $bytes = (string)$thumbnail['bytes'];
            $status = (string)$thumbnail['status'];

            if ($status === 'S3_THUMB_HIT') {
                $this->activity()->success($userId, 'thumbnail', 'S3', $this->fileId($userId, $key), [
                    's3.get_request' => 1,
                    's3.transfer_bytes' => strlen($bytes),
                ], $started, ['cache' => 's3']);
            } elseif ($status === 'GENERATED_ONCE') {
                $this->activity()->success($userId, 'thumbnail', 'S3', $this->fileId($userId, $key), [
                    's3.get_request' => 2,
                    's3.put_request' => 1,
                    's3.transfer_bytes' => strlen($bytes),
                    's3.storage_bytes_delta' => strlen($bytes),
                ], $started, ['cache' => 'generated']);
            }

            $etag = '"' . sha1($bytes) . '"';
            header('Content-Type: ' . (string)$thumbnail['content_type']);
            header('Cache-Control: private, max-age=300');
            header('ETag: ' . $etag);
            header('X-Thumb-Status: ' . $status);
            header('X-Thumb-Key: ' . (string)$thumbnail['thumb_key']);

            if ($this->request->serverString('HTTP_IF_NONE_MATCH') === $etag) {
                http_response_code(304);
                exit;
            }

            echo $bytes;
            exit;
        } catch (Throwable) {
            header('X-Thumb-Status: FALLBACK');
            $this->fallback();
        }
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }

    private function fileId(int $userId, string $key): ?int
    {
        try {
            $row = $this->app->fileRecordLocator()->requireReadableByKey($userId, $key);
            $id = (int)($row['id_'] ?? 0);
            return $id > 0 ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function fallback(): never
    {
        $path = dirname(__DIR__, 3) . '/img/file.png';
        http_response_code(200);
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=60');
        if (is_file($path)) readfile($path);
        else echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
        exit;
    }
}
