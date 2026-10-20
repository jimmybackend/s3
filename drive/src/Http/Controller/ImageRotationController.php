<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;

final class ImageRotationController extends AbstractJsonController
{
    public function rotate(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $this->requireDriveCsrf();
            $key = $this->requireNonEmpty($this->request->postString('key'), 'Falta el archivo.');
            $degrees = (int)$this->request->postString('degrees');
            if (!in_array($degrees, [-90, 90, 180], true)) {
                throw new RuntimeException('Rotación no permitida.');
            }
            $file = $this->app->fileRecordLocator()->requireReadableByKey($userId, $key);
            $key = (string)$file['_key'];
            $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
            $formats = ['jpg' => 'JPEG', 'jpeg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WEBP'];
            if (!isset($formats[$ext])) {
                throw new RuntimeException('Guardar rotación admite JPG, PNG y WebP.');
            }
            if (!class_exists(\Imagick::class)) {
                throw new RuntimeException('El servidor necesita Imagick para guardar rotaciones.');
            }
            $s3 = $this->app->s3();
            $bucket = $this->app->bucket();
            $object = $s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
            $bytes = (string)$object['Body'];
            if ($bytes === '' || strlen($bytes) > 25 * 1024 * 1024) {
                throw new RuntimeException('La imagen está vacía o supera 25 MB.');
            }
            $image = new \Imagick();
            try {
                $image->readImageBlob($bytes);
                if ($image->getNumberImages() !== 1) {
                    throw new RuntimeException('No se admiten imágenes animadas.');
                }
                $image->setIteratorIndex(0);
                $image->rotateImage(new \ImagickPixel('none'), $degrees);
                $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
                $image->setImageFormat($formats[$ext]);
                $rotated = $image->getImageBlob();
            } finally {
                $image->clear();
                $image->destroy();
            }
            if ($rotated === '') {
                throw new RuntimeException('No se pudo procesar la imagen.');
            }
            $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
            $s3->putObject([
                'Bucket' => $bucket, 'Key' => $key, 'Body' => $rotated,
                'ContentType' => $mime,
            ]);
            // The original has been replaced; remove any cached derivatives.
            $this->app->thumbnailService()->invalidateForFile($userId, $key);
            JsonResponse::send(['ok' => true, 'message' => 'Imagen original girada y miniaturas actualizadas.', 'version' => bin2hex(random_bytes(6))]);
        } catch (\Throwable $e) {
            $this->fail($e, $e instanceof RuntimeException ? 400 : 500);
        }
    }
}
