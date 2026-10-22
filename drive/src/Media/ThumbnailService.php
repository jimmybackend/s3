<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use ArcadeCloud\Drive\View\FileViewHelper;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use mysqli;
use RuntimeException;

final class ThumbnailService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'tif', 'tiff'];
    private const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'ogv', 'ogg', '3gp'];

    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private string $localCacheDir
    ) {
        $this->localCacheDir = rtrim($this->localCacheDir, '/\\');
        if (!is_dir($this->localCacheDir)
            && !@mkdir($this->localCacheDir, 0770, true)
            && !is_dir($this->localCacheDir)) {
            throw new RuntimeException('No se pudo crear la caché privada de miniaturas.');
        }
    }

    /**
     * @return array{bytes:string,content_type:string,status:string,thumb_key:string}
     */

    /**
     * Persistent medium preview. The derivative is keyed by scale rather than
     * viewport so every future view can reuse the same S3 object.
     *
     * @return array{bytes:string,content_type:string,status:string,thumb_key:string}
     */
    public function getScaled(int $userId, string $requestedKey, int $scalePercent, array $session): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Sesión inválida para vista previa.');
        }

        $key = $this->normalizeKey($requestedKey);
        $extension = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
        if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException('El archivo no admite vista previa de imagen.');
        }

        $scalePercent = max(10, min(90, $scalePercent));
        $file = $this->lookupFile($userId, $key);
        if ($file === null) {
            throw new RuntimeException('Archivo no encontrado en FileS3.');
        }
        if (!$this->canPreview($file, $key, $session)) {
            throw new RuntimeException('Archivo protegido.');
        }

        $thumbKey = $this->scaledPreviewKey($key, $scalePercent);
        $localPath = $this->localPath($userId, $thumbKey);

        $cached = $this->readLocal($localPath);
        if ($cached !== null) {
            return [
                'bytes' => $cached,
                'content_type' => 'image/jpeg',
                'status' => 'LOCAL_PREVIEW_HIT',
                'thumb_key' => $thumbKey,
            ];
        }

        $this->ensureDirectory(dirname($localPath));
        $lockHandle = @fopen($localPath . '.lock', 'c');
        if ($lockHandle !== false) @flock($lockHandle, LOCK_EX);

        try {
            $cached = $this->readLocal($localPath);
            if ($cached !== null) {
                return [
                    'bytes' => $cached,
                    'content_type' => 'image/jpeg',
                    'status' => 'LOCAL_PREVIEW_HIT_AFTER_LOCK',
                    'thumb_key' => $thumbKey,
                ];
            }

            try {
                $object = $this->s3->getObject([
                    'Bucket' => $this->bucket,
                    'Key' => $thumbKey,
                ]);
                $bytes = (string)$object['Body'];
                if ($bytes !== '') {
                    $this->writeLocal($localPath, $bytes);
                    return [
                        'bytes' => $bytes,
                        'content_type' => 'image/jpeg',
                        'status' => 'S3_PREVIEW_HIT',
                        'thumb_key' => $thumbKey,
                    ];
                }
            } catch (AwsException $e) {
                if (!$this->isNotFound($e)) throw $e;
            }

            $original = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            $sourceBytes = (string)$original['Body'];
            if ($sourceBytes === '') {
                throw new RuntimeException('La imagen original está vacía.');
            }

            try {
                [$sourceWidth, $sourceHeight] = $this->sourceDimensions($sourceBytes);
                $targetWidth = max(1, (int)floor($sourceWidth * ($scalePercent / 100)));
                $targetHeight = max(1, (int)floor($sourceHeight * ($scalePercent / 100)));
                $previewBytes = $this->resizeToJpeg($sourceBytes, $targetWidth, $targetHeight, 'contain');
            } catch (RuntimeException $error) {
                if ($extension === 'avif') {
                    return [
                        'bytes' => $sourceBytes,
                        'content_type' => 'image/avif',
                        'status' => 'SOURCE_PREVIEW',
                        'thumb_key' => $key,
                    ];
                }
                throw $error;
            }

            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $thumbKey,
                'Body' => $previewBytes,
                'ContentType' => 'image/jpeg',
                'ACL' => 'private',
                'Metadata' => [
                    'arcadecloud-source' => $key,
                    'arcadecloud-scale' => (string)$scalePercent,
                ],
            ]);
            $this->writeLocal($localPath, $previewBytes);

            return [
                'bytes' => $previewBytes,
                'content_type' => 'image/jpeg',
                'status' => 'GENERATED_PREVIEW',
                'thumb_key' => $thumbKey,
            ];
        } finally {
            if ($lockHandle !== false) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        }
    }

    public function get(int $userId, string $requestedKey, int $width, int $height, string $fit, array $session): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Sesión inválida para miniatura.');
        }

        $key = $this->normalizeKey($requestedKey);
        $extension = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return $this->getVideo($userId, $key, $width, $height, $session);
        }
        if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException('El archivo no admite miniatura de imagen.');
        }

        $width = max(24, min(512, $width));
        $height = max(24, min(512, $height));
        $fit = strtolower($fit) === 'contain' ? 'contain' : 'cover';

        $file = $this->lookupFile($userId, $key);
        if ($file === null) {
            throw new RuntimeException('Archivo no encontrado en FileS3.');
        }
        if (!$this->canPreview($file, $key, $session)) {
            throw new RuntimeException('Archivo protegido.');
        }

        $thumbKey = $this->thumbnailKey($key, $width, $height, $fit);
        $localPath = $this->localPath($userId, $thumbKey);

        $cached = $this->readLocal($localPath);
        if ($cached !== null) {
            return [
                'bytes' => $cached,
                'content_type' => 'image/jpeg',
                'status' => 'LOCAL_HIT',
                'thumb_key' => $thumbKey,
            ];
        }

        $this->ensureDirectory(dirname($localPath));
        $lockHandle = @fopen($localPath . '.lock', 'c');
        if ($lockHandle !== false) {
            @flock($lockHandle, LOCK_EX);
        }

        try {
            // Otro proceso pudo crearla mientras esperábamos el lock.
            $cached = $this->readLocal($localPath);
            if ($cached !== null) {
                return [
                    'bytes' => $cached,
                    'content_type' => 'image/jpeg',
                    'status' => 'LOCAL_HIT_AFTER_LOCK',
                    'thumb_key' => $thumbKey,
                ];
            }

            // No hacemos HEAD. Intentamos leer directamente la miniatura persistente.
            // Si existe en S3, esta es la única lectura S3 necesaria tras perder caché local.
            try {
                $object = $this->s3->getObject([
                    'Bucket' => $this->bucket,
                    'Key' => $thumbKey,
                ]);
                $bytes = (string)$object['Body'];
                if ($bytes !== '') {
                    $this->writeLocal($localPath, $bytes);
                    return [
                        'bytes' => $bytes,
                        'content_type' => 'image/jpeg',
                        'status' => 'S3_THUMB_HIT',
                        'thumb_key' => $thumbKey,
                    ];
                }
            } catch (AwsException $e) {
                if (!$this->isNotFound($e)) {
                    throw $e;
                }
            }

            // Primera vez real: leer original, generar una sola miniatura y persistirla.
            $original = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            $sourceBytes = (string)$original['Body'];
            if ($sourceBytes === '') {
                throw new RuntimeException('La imagen original está vacía.');
            }

            try {
                $thumbnailBytes = $this->resizeToJpeg($sourceBytes, $width, $height, $fit);
            } catch (RuntimeException $error) {
                if ($extension === 'avif') {
                    return [
                        'bytes' => $sourceBytes,
                        'content_type' => 'image/avif',
                        'status' => 'SOURCE_PREVIEW',
                        'thumb_key' => $key,
                    ];
                }
                throw $error;
            }

            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $thumbKey,
                'Body' => $thumbnailBytes,
                'ContentType' => 'image/jpeg',
                'ACL' => 'private',
            ]);
            $this->writeLocal($localPath, $thumbnailBytes);

            return [
                'bytes' => $thumbnailBytes,
                'content_type' => 'image/jpeg',
                'status' => 'GENERATED_ONCE',
                'thumb_key' => $thumbKey,
            ];
        } finally {
            if ($lockHandle !== false) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        }
    }


    /** Private persistent video poster, extracted with bounded FFmpeg execution. */
    private function getVideo(int $userId, string $key, int $width, int $height, array $session): array
    {
        $width = max(24, min(512, $width));
        $height = max(24, min(512, $height));
        $file = $this->lookupFile($userId, $key);
        if ($file === null || !$this->canPreview($file, $key, $session)) {
            throw new RuntimeException('Video no disponible para vista previa.');
        }
        $thumbKey = 'video-posters/' . hash('sha256', $key) . '/' . $width . 'x' . $height . '.jpg';
        $localPath = $this->localPath($userId, $thumbKey);
        $cached = $this->readLocal($localPath);
        if ($cached !== null) return ['bytes'=>$cached,'content_type'=>'image/jpeg','status'=>'LOCAL_HIT','thumb_key'=>$thumbKey];
        $this->ensureDirectory(dirname($localPath));
        $lock = @fopen($localPath . '.lock', 'c');
        if ($lock !== false) @flock($lock, LOCK_EX);
        try {
            $cached = $this->readLocal($localPath);
            if ($cached !== null) return ['bytes'=>$cached,'content_type'=>'image/jpeg','status'=>'LOCAL_HIT_AFTER_LOCK','thumb_key'=>$thumbKey];
            try {
                $object = $this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$thumbKey]);
                $bytes = (string)$object['Body'];
                if ($bytes !== '') {
                    $this->writeLocal($localPath,$bytes);
                    return ['bytes'=>$bytes,'content_type'=>'image/jpeg','status'=>'S3_THUMB_HIT','thumb_key'=>$thumbKey];
                }
            } catch (AwsException $e) { if (!$this->isNotFound($e)) throw $e; }
            if (!function_exists('proc_open') || !is_executable('/usr/bin/ffmpeg') || !is_executable('/usr/bin/timeout')) {
                throw new RuntimeException('Miniatura pendiente: FFmpeg no disponible.');
            }
            $head = $this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$key]);
            if ((int)($head['ContentLength'] ?? 0) > 150 * 1024 * 1024) {
                throw new RuntimeException('Video grande pendiente de procesamiento.');
            }
            $signed = $this->s3->createPresignedRequest(
                $this->s3->getCommand('GetObject',['Bucket'=>$this->bucket,'Key'=>$key]),'+2 minutes');
            $filter = 'scale=' . $width . ':' . $height
                . ':force_original_aspect_ratio=decrease,pad=' . $width . ':' . $height . ':(ow-iw)/2:(oh-ih)/2';
            $args = ['/usr/bin/timeout','-k','2','12','/usr/bin/ffmpeg','-nostdin','-hide_banner',
                '-loglevel','error','-threads','1','-ss','1','-i',(string)$signed->getUri(),
                '-frames:v','1','-vf',$filter,'-f','image2pipe','-vcodec','mjpeg','pipe:1'];
            $pipes = [];
            $process = @proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('FFmpeg no pudo iniciarse.');
            fclose($pipes[0]);
            $bytes = stream_get_contents($pipes[1],1024*1024+1);
            fclose($pipes[1]);
            // Do not expose signed S3 URLs contained in stderr.
            stream_get_contents($pipes[2],8192);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0 || !is_string($bytes) || strlen($bytes)>1024*1024
                || !str_starts_with($bytes,"\xFF\xD8")) {
                throw new RuntimeException('No se pudo generar la miniatura del video.');
            }
            $this->s3->putObject(['Bucket'=>$this->bucket,'Key'=>$thumbKey,'Body'=>$bytes,
                'ContentType'=>'image/jpeg','ACL'=>'private']);
            $this->writeLocal($localPath,$bytes);
            return ['bytes'=>$bytes,'content_type'=>'image/jpeg','status'=>'GENERATED_ONCE','thumb_key'=>$thumbKey];
        } finally {
            if ($lock !== false) { @flock($lock,LOCK_UN); @fclose($lock); }
        }
    }

    /** Remove all variants generated from this original (S3 and local cache). */
    public function invalidateForFile(int $userId, string $key): void
    {
        $key = $this->normalizeKey($key);
        $root = 'Data/';
        $rest = $key;
        if (preg_match('~^(Data\\d*/)(.*)$~i', $key, $match)) {
            $root = $match[1];
            $rest = $match[2];
        }
        $withoutExtension = preg_replace('/\\.[A-Za-z0-9]+$/', '', $rest) ?? $rest;
        $prefix = 'thumbs/' . $root . $withoutExtension . '__';
        $continuation = null;
        do {
            $options = ['Bucket' => $this->bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000];
            if ($continuation !== null) $options['ContinuationToken'] = $continuation;
            $result = $this->s3->listObjectsV2($options);
            foreach (($result['Contents'] ?? []) as $item) {
                $variantKey = (string)($item['Key'] ?? '');
                if (!str_starts_with($variantKey, $prefix) || !str_ends_with($variantKey, '.jpg')) continue;
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $variantKey]);
                @unlink($this->localPath($userId, $variantKey));
            }
            $continuation = !empty($result['IsTruncated'])
                ? (string)($result['NextContinuationToken'] ?? '')
                : null;
            if ($continuation === '') break;
        } while ($continuation !== null);
    }

    private function lookupFile(int $userId, string $key): ?array
    {
        $slash = strrpos($key, '/');
        $route = $slash === false ? '' : substr($key, 0, $slash + 1);
        $basename = $slash === false ? $key : substr($key, $slash + 1);

        $stmt = $this->db->prepare(
            'SELECT id_, Nombre, Ruta, Encriptado, AccessType, PasswordHash, SecureHint, Found
             FROM FileS3
             WHERE user_id_ = ? AND Found = 1
               AND ((Ruta = ? AND Encriptado = ?) OR Encriptado = ?)
             ORDER BY id_ DESC
             LIMIT 20'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar la miniatura en FileS3: ' . $this->db->error);
        }

        $stmt->bind_param('isss', $userId, $route, $basename, $key);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $realKey = $this->normalizeKey(FileViewHelper::buildS3Key(
                (string)($row['Ruta'] ?? ''),
                (string)($row['Encriptado'] ?? '')
            ));
            if ($realKey === $key) {
                $stmt->close();
                return $row;
            }
        }

        $stmt->close();
        return null;
    }

    private function canPreview(array $row, string $key, array $session): bool
    {
        $accessType = strtolower(trim((string)($row['AccessType'] ?? 'normal')));
        $passwordHash = trim((string)($row['PasswordHash'] ?? ''));

        if ($accessType === 'unlocked') {
            return true;
        }
        if ($accessType !== 'secure' && $passwordHash === '') {
            return true;
        }

        $candidateKeys = array_values(array_unique(array_filter([
            $key,
            (string)($row['Encriptado'] ?? ''),
            basename($key),
        ])));

        foreach (['secure_ok_files', 'secure_files_ok', 'unlocked_files'] as $bucketName) {
            $bucket = $session[$bucketName] ?? null;
            if (!is_array($bucket)) {
                continue;
            }
            foreach ($candidateKeys as $candidate) {
                if (!array_key_exists($candidate, $bucket)) {
                    continue;
                }
                $value = $bucket[$candidate];
                if (is_numeric($value) && (int)$value < time()) {
                    continue;
                }
                if ($value) {
                    return true;
                }
            }
        }

        return false;
    }

    private function thumbnailKey(string $key, int $width, int $height, string $fit): string
    {
        $root = 'Data/';
        $rest = $key;
        if (preg_match('~^(Data\\d*/)(.*)$~i', $key, $match)) {
            $root = $match[1];
            $rest = $match[2];
        }

        $withoutExtension = preg_replace('/\\.[A-Za-z0-9]+$/', '', $rest) ?? $rest;
        return 'thumbs/' . $root . $withoutExtension . '__'
            . $width . 'x' . $height . '_' . $fit . '.jpg';
    }

    private function scaledPreviewKey(string $key, int $scalePercent): string
    {
        $root = 'Data/';
        $rest = $key;
        if (preg_match('~^(Data\\d*/)(.*)$~i', $key, $match)) {
            $root = $match[1];
            $rest = $match[2];
        }

        $withoutExtension = preg_replace('/\\.[A-Za-z0-9]+$/', '', $rest) ?? $rest;
        return 'thumbs/' . $root . $withoutExtension . '__preview'
            . $scalePercent . '.jpg';
    }

    /** @return array{0:int,1:int} */
    private function sourceDimensions(string $bytes): array
    {
        if (class_exists('Imagick')) {
            $image = new \Imagick();
            if (!$image->readImageBlob($bytes)) {
                throw new RuntimeException('Imagick no pudo leer las dimensiones.');
            }
            if ($image->getNumberImages() > 1) $image->setIteratorIndex(0);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $image->clear();
            if ($width > 0 && $height > 0) return [$width, $height];
        }

        if (function_exists('getimagesizefromstring')) {
            $size = @getimagesizefromstring($bytes);
            if (is_array($size) && (int)($size[0] ?? 0) > 0 && (int)($size[1] ?? 0) > 0) {
                return [(int)$size[0], (int)$size[1]];
            }
        }

        throw new RuntimeException('No se pudieron leer las dimensiones de la imagen.');
    }

    private function localPath(int $userId, string $thumbKey): string
    {
        $hash = hash('sha256', $userId . '|' . $thumbKey);
        return $this->localCacheDir
            . DIRECTORY_SEPARATOR . $userId
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . $hash . '.jpg';
    }

    private function readLocal(string $path): ?string
    {
        if (!is_file($path) || filesize($path) <= 0) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    private function writeLocal(string $path, string $bytes): void
    {
        $this->ensureDirectory(dirname($path));
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(5));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            @unlink($tmp);
            return; // La caché local es una optimización, no debe romper la miniatura.
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace(["\\0", '\\'], ['', '/'], trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        $key = ltrim($key, '/');
        if ($key === '' || str_contains('/' . $key . '/', '/../')) {
            throw new RuntimeException('Key de miniatura inválida.');
        }
        return $key;
    }

    private function isNotFound(AwsException $e): bool
    {
        return $e->getStatusCode() === 404
            || in_array((string)$e->getAwsErrorCode(), ['NoSuchKey', 'NotFound', '404'], true);
    }

    private function resizeToJpeg(string $bytes, int $width, int $height, string $fit): string
    {
        if (class_exists('Imagick')) {
            $image = new \Imagick();
            if (!$image->readImageBlob($bytes)) {
                throw new RuntimeException('Imagick no pudo leer la imagen.');
            }
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
            }
            if (method_exists($image, 'autoOrientImage')) {
                @$image->autoOrientImage();
            }
            $image->setImageBackgroundColor('white');
            if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                @$image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            }
            $image->setImageFormat('jpeg');

            if ($fit === 'contain') {
                $image->thumbnailImage($width, $height, true, true);
                $canvas = new \Imagick();
                $canvas->newImage($width, $height, 'white', 'jpeg');
                $x = (int)(($width - $image->getImageWidth()) / 2);
                $y = (int)(($height - $image->getImageHeight()) / 2);
                $canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, $x, $y);
                $canvas->setImageCompressionQuality(82);
                $output = $canvas->getImageBlob();
                $canvas->clear();
                $image->clear();
                return $output;
            }

            $image->cropThumbnailImage($width, $height);
            $image->setImageCompressionQuality(82);
            $output = $image->getImageBlob();
            $image->clear();
            return $output;
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('El servidor necesita Imagick o GD para crear miniaturas.');
        }

        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            throw new RuntimeException('GD no pudo leer la imagen.');
        }

        $sourceWidth = imagesx($src);
        $sourceHeight = imagesy($src);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($src);
            throw new RuntimeException('Dimensiones de imagen inválidas.');
        }

        if ($fit === 'contain') {
            $scale = min($width / $sourceWidth, $height / $sourceHeight);
            $newWidth = max(1, (int)floor($sourceWidth * $scale));
            $newHeight = max(1, (int)floor($sourceHeight * $scale));
            $dst = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $width, $height, $white);
            $x = (int)floor(($width - $newWidth) / 2);
            $y = (int)floor(($height - $newHeight) / 2);
            imagecopyresampled($dst, $src, $x, $y, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
        } else {
            $scale = max($width / $sourceWidth, $height / $sourceHeight);
            $newWidth = max(1, (int)ceil($sourceWidth * $scale));
            $newHeight = max(1, (int)ceil($sourceHeight * $scale));
            $tmp = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($tmp, $src, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
            $dst = imagecreatetruecolor($width, $height);
            $x = (int)floor(($newWidth - $width) / 2);
            $y = (int)floor(($newHeight - $height) / 2);
            imagecopy($dst, $tmp, 0, 0, $x, $y, $width, $height);
            imagedestroy($tmp);
        }

        ob_start();
        imagejpeg($dst, null, 82);
        $output = (string)ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $output;
    }
}
