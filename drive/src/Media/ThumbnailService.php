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
    public function get(int $userId, string $requestedKey, int $width, int $height, string $fit, array $session): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Sesión inválida para miniatura.');
        }

        $key = $this->normalizeKey($requestedKey);
        $extension = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
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

            $thumbnailBytes = $this->resizeToJpeg($sourceBytes, $width, $height, $fit);

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
