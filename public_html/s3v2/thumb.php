<?php
/**
 * thumb.php — Genera (si falta) y sirve miniaturas guardadas en S3.
 *
 * CAMBIO CLAVE:
 *  - La key del thumb AHORA incluye w/h/fit para que NO regenere por tamaños distintos:
 *      thumbs/Data1/ruta/archivo__128x128_cover.jpg
 *
 * MODO HOSTING SIN FFMPEG:
 *  - Para VIDEOS: devuelve icono fijo (img/video.png o img/video.jpg).
 *  - Para IMÁGENES: genera JPG y lo sube a S3 solo si NO existe.
 *
 * Mantiene:
 *  - Normalización de keys duplicadas (Data/Data y prefijo largo duplicado)
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

use Aws\Exception\AwsException;

function thumbLog(string $msg): void { return; } // deja así o habilita log si lo necesitas

function outputLocalImage(string $pathPngOrJpg): void {
    if (!is_file($pathPngOrJpg)) {
        http_response_code(200);
        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
        exit;
    }

    $ext = strtolower(pathinfo($pathPngOrJpg, PATHINFO_EXTENSION));
    $ct = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';

    http_response_code(200);
    header('Content-Type: ' . $ct);
    header('Cache-Control: no-store');
    readfile($pathPngOrJpg);
    exit;
}

function outputFallbackFile(): void {
    outputLocalImage(__DIR__ . '/img/file.png');
}

function outputVideoIcon(): void {
    $iconPng = __DIR__ . '/img/video.png';
    $iconJpg = __DIR__ . '/img/video.jpg';
    if (is_file($iconPng)) outputLocalImage($iconPng);
    if (is_file($iconJpg)) outputLocalImage($iconJpg);
    outputFallbackFile();
}

function getInt(string $k, int $default): int {
    $v = filter_input(INPUT_GET, $k, FILTER_VALIDATE_INT);
    return ($v === false || $v === null) ? $default : $v;
}

function getStr(string $k, string $default): string {
    $v = filter_input(INPUT_GET, $k, FILTER_UNSAFE_RAW);
    if ($v === null || $v === false) return $default;
    return trim((string)$v);
}

function safeKey(string $raw): string {
    $raw = str_replace("\0", '', $raw);
    return ltrim($raw, '/');
}

/**
 * Normaliza keys duplicadas:
 *  A) DataN/ repetido: Data/Data/x => Data/x
 *  B) Prefijo largo duplicado: A/B/C/A/B/C/file => A/B/C/file
 */
function normalizeOrigKey(string $k): string {
    $k = ltrim($k, '/');
    $k = preg_replace('~/{2,}~', '/', $k) ?? $k;

    // A) DataN/ repetido
    for ($i = 0; $i < 3; $i++) {
        if (preg_match('~^(Data\d*/)(\1)+~i', $k)) {
            $k2 = preg_replace('~^(Data\d*/)(\1)+~i', '$1', $k);
            if ($k2 === null || $k2 === $k) break;
            $k = $k2;
        } else break;
    }

    // B) prefijo completo duplicado por segmentos
    $parts = array_values(array_filter(explode('/', $k), function ($p) {
    return $p !== null && $p !== '';
}));
    $n = count($parts);
    if ($n >= 4) {
        $best = 0;
        $maxK = intdiv($n, 2);
        for ($kSeg = 1; $kSeg <= $maxK; $kSeg++) {
            $a = array_slice($parts, 0, $kSeg);
            $b = array_slice($parts, $kSeg, $kSeg);
            if ($a === $b) $best = $kSeg;
        }
        if ($best > 0) {
            $newParts = array_merge(array_slice($parts, 0, $best), array_slice($parts, $best * 2));
            $kNew = implode('/', $newParts);
            if ($kNew !== '' && $kNew !== $k) $k = $kNew;
        }
    }

    return $k;
}

function isImageExt(string $ext): bool {
    $ext = strtolower($ext);
    return in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true);
}
function isVideoExt(string $ext): bool {
    $ext = strtolower($ext);
    return in_array($ext, ['mp4','mov','webm','mkv','avi','m4v'], true);
}

function computeThumbBase(int $uid): string {
    if ($uid <= 1) return 'thumbs/Data/';
    return 'thumbs/Data' . $uid . '/';
}

function stripLeadingData(string $origKey): string {
    $k = ltrim($origKey, '/');
    if (preg_match('~^Data\d*/~i', $k, $m)) {
        return substr($k, strlen($m[0]));
    }
    return $k;
}

/**
 * NUEVO: el thumbKey incluye tamaño/fit para que quede cacheado por variante
 */
function makeThumbKey(string $origKey, int $uid, string $outExt, int $w, int $h, string $fit): string {
    $base = computeThumbBase($uid);
    $rest = stripLeadingData($origKey);

    $restNoExt = preg_replace('/\.[A-Za-z0-9]+$/', '', $rest);
    if ($restNoExt === null) $restNoExt = $rest;

    $fit = strtolower($fit);
    if ($fit !== 'contain') $fit = 'cover';

    return $base . $restNoExt . '__' . $w . 'x' . $h . '_' . $fit . '.' . $outExt;
}

// --- Resize: Imagick si existe, si no GD ---
function resizeToJpeg(string $bin, int $w, int $h, string $fit = 'cover'): string {
    $fit = strtolower($fit);
    if ($fit !== 'contain') $fit = 'cover';

    if (class_exists('Imagick')) {
        $im = new Imagick();
        if (!$im->readImageBlob($bin)) throw new RuntimeException('Imagick: no se pudo leer la imagen.');

        $im->setImageColorspace(Imagick::COLORSPACE_RGB);
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $im->setBackgroundColor('white');
        $im->setImageFormat('jpeg');

        if ($fit === 'contain') {
            $im->thumbnailImage($w, $h, true, true);
            $canvas = new Imagick();
            $canvas->newImage($w, $h, 'white', 'jpeg');
            $x = (int)(($w - $im->getImageWidth()) / 2);
            $y = (int)(($h - $im->getImageHeight()) / 2);
            $canvas->compositeImage($im, Imagick::COMPOSITE_OVER, $x, $y);
            $canvas->setImageCompressionQuality(82);
            return $canvas->getImageBlob();
        }

        $im->cropThumbnailImage($w, $h);
        $im->setImageCompressionQuality(82);
        return $im->getImageBlob();
    }

    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('GD no está disponible.');
    $src = @imagecreatefromstring($bin);
    if (!$src) throw new RuntimeException('GD: no se pudo leer la imagen.');

    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0) { imagedestroy($src); throw new RuntimeException('GD: dimensiones inválidas.'); }

    if ($fit === 'contain') {
        $scale = min($w / $sw, $h / $sh);
        $nw = (int)max(1, floor($sw * $scale));
        $nh = (int)max(1, floor($sh * $scale));

        $dst = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $w, $h, $white);

        $x = (int)floor(($w - $nw) / 2);
        $y = (int)floor(($h - $nh) / 2);
        imagecopyresampled($dst, $src, $x, $y, 0, 0, $nw, $nh, $sw, $sh);
    } else {
        $scale = max($w / $sw, $h / $sh);
        $nw = (int)ceil($sw * $scale);
        $nh = (int)ceil($sh * $scale);

        $tmp = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);

        $dst = imagecreatetruecolor($w, $h);
        $x = (int)floor(($nw - $w) / 2);
        $y = (int)floor(($nh - $h) / 2);
        imagecopy($dst, $tmp, 0, 0, $x, $y, $w, $h);

        imagedestroy($tmp);
    }

    ob_start();
    imagejpeg($dst, null, 82);
    $out = (string)ob_get_clean();

    imagedestroy($src);
    imagedestroy($dst);

    return $out;
}

// ================== MAIN ==================
$keyRaw = $_GET['key'] ?? '';
$origKeyRaw = safeKey((string)$keyRaw);
$origKey = normalizeOrigKey($origKeyRaw);

$uid = getInt('uid', 1);
$w   = max(16, min(512, getInt('w', 128)));
$h   = max(16, min(512, getInt('h', 128)));
$fit = strtolower(getStr('fit', 'cover'));
if ($fit !== 'contain') $fit = 'cover';

if ($origKey === '') {
    http_response_code(400);
    outputFallbackFile();
}

$ext = strtolower((string)pathinfo($origKey, PATHINFO_EXTENSION));
$isImg = isImageExt($ext);
$isVid = isVideoExt($ext);

if (!$isImg && !$isVid) {
    http_response_code(415);
    outputFallbackFile();
}

try {
    $s3 = Config::getS3();
    $bucket = (new S3Manager())->getBucket();

    // Keys de thumbs (ahora con w/h/fit)
    $thumbKeyJpg = makeThumbKey($origKey, $uid, 'jpg', $w, $h, $fit);
    $thumbKeyGif = $isVid ? makeThumbKey($origKey, $uid, 'gif', $w, $h, $fit) : '';

    header('X-Thumb-Bucket: ' . $bucket);
    header('X-Thumb-OrigKey: ' . $origKey);
    if ($origKeyRaw !== $origKey) header('X-Thumb-OrigKey-Raw: ' . $origKeyRaw);
    header('X-Thumb-Key-Jpg: ' . $thumbKeyJpg);

    // 1) HIT: si existe thumb, servirlo (sin regenerar)
    if ($isVid) {
        // primero gif si existiera
        if ($thumbKeyGif !== '' && $s3->doesObjectExistV2($bucket, $thumbKeyGif)) {
            $obj = $s3->getObject(['Bucket' => $bucket, 'Key' => $thumbKeyGif]);
            header('Content-Type: ' . ($obj['ContentType'] ?? 'image/gif'));
            header('Cache-Control: public, max-age=604800');
            header('X-Thumb-Status: HIT_GIF');
            echo (string)$obj['Body'];
            exit;
        }

        if ($s3->doesObjectExistV2($bucket, $thumbKeyJpg)) {
            $obj = $s3->getObject(['Bucket' => $bucket, 'Key' => $thumbKeyJpg]);
            header('Content-Type: ' . ($obj['ContentType'] ?? 'image/jpeg'));
            header('Cache-Control: public, max-age=604800');
            header('X-Thumb-Status: HIT_JPG');
            echo (string)$obj['Body'];
            exit;
        }

        // SIN FFMPEG: icono fijo para videos
        header('X-Thumb-Status: VIDEO_ICON');
        outputVideoIcon();
    }

    // IMAGEN: HIT
    if ($s3->doesObjectExistV2($bucket, $thumbKeyJpg)) {
        $obj = $s3->getObject(['Bucket' => $bucket, 'Key' => $thumbKeyJpg]); 
        header('Content-Type: ' . ($obj['ContentType'] ?? 'image/jpeg'));
        header('Cache-Control: public, max-age=604800');
        header('X-Thumb-Status: HIT_JPG');
        echo (string)$obj['Body'];
        exit;
    }

    // 2) Descargar original (imagen)
    try {
        $origObj = $s3->getObject(['Bucket' => $bucket, 'Key' => $origKey]);
        $bin = (string)$origObj['Body'];
    } catch (AwsException $e) {
        http_response_code(404);
        header('X-Thumb-Status: ORIG_404');
        outputFallbackFile();
    }

    // 3) Generar JPG y subir (solo si NO existía)
    $thumbBin = resizeToJpeg($bin, $w, $h, $fit);

    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $thumbKeyJpg,
        'Body'        => $thumbBin,
        'ContentType' => 'image/jpeg',
        'ACL'         => 'private',
    ]);

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=604800');
    header('X-Thumb-Status: GEN_IMG');
    echo $thumbBin;
    exit;

} catch (AwsException $e) {
    header('X-Thumb-Status: AWS_ERR');
    outputFallbackFile();
} catch (Throwable $e) {
    header('X-Thumb-Status: FATAL');
    outputFallbackFile();
}