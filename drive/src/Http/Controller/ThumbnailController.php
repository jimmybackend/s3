<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

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
        $fit = strtolower($this->request->queryString('fit', 'cover')) === 'contain'
            ? 'contain'
            : 'cover';

        $userId = $session->userId();
        $sessionSnapshot = $session->snapshot();
        $session->closeWrite();

        try {
            $thumbnail = $this->app->thumbnailService()->get(
                $userId,
                $key,
                $width,
                $height,
                $fit,
                $sessionSnapshot
            );

            $bytes = (string)$thumbnail['bytes'];
            $etag = '"' . sha1($bytes) . '"';

            header('Content-Type: ' . (string)$thumbnail['content_type']);
            header('Cache-Control: private, max-age=300');
            header('ETag: ' . $etag);
            header('X-Thumb-Status: ' . (string)$thumbnail['status']);
            header('X-Thumb-Key: ' . (string)$thumbnail['thumb_key']);

            if ($this->request->serverString('HTTP_IF_NONE_MATCH') === $etag) {
                http_response_code(304);
                exit;
            }

            echo $bytes;
            exit;
        } catch (Throwable $e) {
            header('X-Thumb-Status: FALLBACK');
            $this->fallback();
        }
    }

    private function fallback(): never
    {
        $path = dirname(__DIR__, 3) . '/img/file.png';

        http_response_code(200);
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=60');

        if (is_file($path)) {
            readfile($path);
        } else {
            echo base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII='
            );
        }

        exit;
    }
}
