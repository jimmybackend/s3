<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Media\ThumbnailService;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/app_bootstrap.php';

function thumbnailFallback(): never
{
    $path = __DIR__ . '/img/file.png';
    http_response_code(200);
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=60');
    if (is_file($path)) {
        readfile($path);
    } else {
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
    }
    exit;
}

$app = drive_app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    header('X-Thumb-Status: NO_SESSION');
    thumbnailFallback();
}

$key = trim((string)($_GET['key'] ?? ''));
$width = max(24, min(512, (int)($_GET['w'] ?? 96)));
$height = max(24, min(512, (int)($_GET['h'] ?? 96)));
$fit = strtolower(trim((string)($_GET['fit'] ?? 'cover'))) === 'contain' ? 'contain' : 'cover';

if ($key === '') {
    header('X-Thumb-Status: NO_KEY');
    thumbnailFallback();
}

$userId = $session->userId();
$sessionSnapshot = $_SESSION;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new ThumbnailService(
        $app->db(),
        $app->s3(),
        $app->bucket(),
        sys_get_temp_dir() . '/arcadecloud-drive-thumbnails'
    );

    $thumbnail = $service->get($userId, $key, $width, $height, $fit, $sessionSnapshot);
    $bytes = $thumbnail['bytes'];
    $etag = '"' . sha1($bytes) . '"';

    header('Content-Type: ' . $thumbnail['content_type']);
    header('Cache-Control: private, max-age=300');
    header('ETag: ' . $etag);
    header('X-Thumb-Status: ' . $thumbnail['status']);
    header('X-Thumb-Key: ' . $thumbnail['thumb_key']);

    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    echo $bytes;
} catch (Throwable $e) {
    header('X-Thumb-Status: FALLBACK');
    thumbnailFallback();
}
