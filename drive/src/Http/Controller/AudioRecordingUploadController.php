<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;
use Throwable;

final class AudioRecordingUploadController extends AbstractJsonController
{
    private const MAX_BYTES = 104857600; // 100 MiB

    public function upload(): never
    {
        $userId = 0;

        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $this->requireUploadCsrf();

            $files = $this->request->files();
            $audio = $files['audio'] ?? null;
            if (!is_array($audio) || (int)($audio['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                JsonResponse::error('No se recibió el archivo de audio.', 400);
            }

            $tmpPath = (string)($audio['tmp_name'] ?? '');
            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                throw new RuntimeException('Archivo temporal de audio inválido.');
            }

            $size = (int)(filesize($tmpPath) ?: 0);
            if ($size <= 0) {
                JsonResponse::error('La grabación está vacía.', 400);
            }
            if ($size > self::MAX_BYTES) {
                JsonResponse::error('El archivo es demasiado grande (máx. 100 MB).', 413);
            }

            $mime = strtolower(trim((string)(mime_content_type($tmpPath) ?: 'application/octet-stream')));
            if (!str_starts_with($mime, 'audio/') && $mime !== 'video/webm') {
                JsonResponse::error('El archivo recibido no es una grabación de audio válida.', 415);
            }

            $filename = $this->safeFilename($this->request->postString('filename'), $mime);
            $route = $this->app->userStoragePath()->normalizeForUser(
                (string)$this->app->session()->get('ruta_actual', ''),
                $userId
            );

            $result = $this->app->singleUploadService()->upload(
                $tmpPath,
                $filename,
                $route,
                $userId,
                $mime,
                $size,
                $this->request->serverString('REMOTE_ADDR', 'unknown'),
                $this->request->serverString('HTTP_USER_AGENT', 'unknown')
            );

            JsonResponse::send([
                'ok' => true,
                'key' => (string)($result['key_s3'] ?? ''),
                'bucket' => $this->app->bucket(),
                'mime' => $mime,
                'versionId' => null,
                'data' => $result,
            ]);
        } catch (Throwable $error) {
            if ($userId > 0) {
                error_log('[ArcadeCloud audio upload] ' . $error::class . ': ' . $error->getMessage());
            }
            $this->fail($error, 500, 'No se pudo guardar la grabación.');
        }
    }

    private function requireUploadCsrf(): void
    {
        $expected = (string)$this->app->session()->get('upload_csrf', '');
        $sent = $this->request->serverString('HTTP_X_DRIVE_CSRF');

        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            JsonResponse::error('Token CSRF inválido. Recarga el Drive.', 403);
        }
    }

    private function safeFilename(string $filename, string $mime): string
    {
        $filename = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', ' ', $filename) ?? '');
        $filename = trim(preg_replace('/\s+/u', ' ', $filename) ?? $filename);

        if ($filename !== '') {
            return mb_substr(basename($filename), 0, 255);
        }

        $extension = match (true) {
            str_contains($mime, 'ogg') => '.ogg',
            str_contains($mime, 'mp4') => '.m4a',
            default => '.webm',
        };

        return 'Grabacion_' . date('Y-m-d_H-i-s') . $extension;
    }
}
