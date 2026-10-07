<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;
use Throwable;

final class Drive3dBackgroundUploadController extends AbstractJsonController
{
    private const MAX_BYTES = 15728640; // 15 MiB

    public function upload(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $this->requireDriveCsrf();

            $files = $this->request->files();
            $image = $files['background'] ?? null;
            if (!is_array($image) || (int)($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                JsonResponse::error('No se recibió una imagen válida.', 400);
            }

            $tmpPath = (string)($image['tmp_name'] ?? '');
            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                throw new RuntimeException('Archivo temporal inválido.');
            }

            $size = (int)(filesize($tmpPath) ?: 0);
            if ($size <= 0) JsonResponse::error('La imagen está vacía.', 400);
            if ($size > self::MAX_BYTES) JsonResponse::error('La imagen supera 15 MB.', 413);

            $mime = strtolower((string)(mime_content_type($tmpPath) ?: ''));
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'image/avif' => 'avif',
            ];
            if (!isset($allowed[$mime])) {
                JsonResponse::error('Formato no permitido. Usa JPG, PNG, WEBP, GIF o AVIF.', 415);
            }

            $rawName = trim((string)($image['name'] ?? 'fondo.' . $allowed[$mime]));
            $name = preg_replace('/[^\pL\pN._ -]+/u', '_', basename($rawName)) ?? '';
            $name = trim($name, " .\t\n\r\0\x0B");
            if ($name === '') $name = 'fondo_' . date('Ymd_His') . '.' . $allowed[$mime];
            $name = mb_substr($name, 0, 220);

            $root = $this->app->userStoragePath()->rootForUser($userId);
            $images = rtrim($root, '/') . '/Imagenes/';
            $route = $images . 'fondos3D/';

            $catalog = $this->app->uploadCatalogRepository();
            $catalog->ensureFolder($userId, $images, 'Imagenes', $root);
            $catalog->ensureFolder($userId, $route, 'fondos3D', $images);

            $result = $this->app->singleUploadService()->upload(
                $tmpPath,
                $name,
                $route,
                $userId,
                $mime,
                $size,
                $this->request->serverString('REMOTE_ADDR', 'unknown'),
                $this->request->serverString('HTTP_USER_AGENT', 'unknown')
            );

            $key = (string)($result['key_s3'] ?? '');
            $displayRoute = $this->app->folderQueryService()->displayPathForUser($userId, $route);
            $visiblePath = rtrim($displayRoute, '/') . '/' . $name;
            JsonResponse::ok([
                'name' => $name,
                'route' => $displayRoute,
                'visiblePath' => $visiblePath,
                'storageRoute' => $route,
                'key' => $key,
                'thumbnail' => $key !== '' ? 'thumb.php?key=' . rawurlencode($key) . '&w=420&h=280&fit=cover' : '',
                'environment' => $key !== '' ? 'thumb.php?key=' . rawurlencode($key) . '&w=1920&h=1080&fit=cover' : '',
            ], 201);
        } catch (Throwable $error) {
            $this->fail($error, 500, 'No se pudo subir el fondo 3D.');
        }
    }
}
