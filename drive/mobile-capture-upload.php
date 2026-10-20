<?php
declare(strict_types=1);
require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\JsonResponse;

try {
    $app = ApplicationKernel::app();
    $session = $app->session();
    $session->start();
    if (!$session->isAuthenticated() || $session->userId() <= 0) {
        JsonResponse::error('Sesión inválida.', 401);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        JsonResponse::error('Método no permitido.', 405);
    }
    $csrf = (string)$session->get('upload_csrf', '');
    $sent = (string)($_SERVER['HTTP_X_DRIVE_CSRF'] ?? '');
    if ($csrf === '' || $sent === '' || !hash_equals($csrf, $sent)) {
        JsonResponse::error('Token CSRF inválido.', 403);
    }

    $type = (string)($_POST['tipo'] ?? '');
    if (!in_array($type, ['audio', 'photo'], true)) {
        JsonResponse::error('Tipo de archivo inválido.', 400);
    }
    $file = $_FILES['archivo'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        JsonResponse::error('Archivo no recibido.', 400);
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        JsonResponse::error('Archivo temporal inválido.', 400);
    }
    $size = (int)(filesize($tmp) ?: 0);
    if ($size < 1 || $size > 25 * 1024 * 1024) {
        JsonResponse::error('El archivo excede 25 MB o está vacío.', 413);
    }
    $mime = strtolower((string)(mime_content_type($tmp) ?: ''));
    $allowed = $type === 'audio'
        ? ['audio/webm', 'video/webm', 'audio/mp4', 'audio/x-m4a', 'audio/ogg']
        : ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        JsonResponse::error('Formato no permitido.', 415);
    }
    $name = (string)($file['name'] ?? '');
    $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name)) ?: '';
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $extensions = $type === 'audio' ? ['webm','m4a','mp4','ogg'] : ['jpg','jpeg','png','webp'];
    if ($name === '' || strlen($name) > 180 || !in_array($extension, $extensions, true)) {
        JsonResponse::error('Nombre de archivo inválido.', 400);
    }
    $userId = $session->userId();
    $route = $app->uploadDestinationService()->resolve(
        $userId, (string)($_POST['ruta_objetivo'] ?? '')
    );
    $session->closeWrite();
    $started = microtime(true);
    $result = $app->singleUploadService()->upload(
        $tmp, $name, $route, $userId, $mime, $size,
        (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'ArcadeCloud Mobile'
    );
    ActivityCostRecorder::fromDatabase($app->db())->success(
        $userId, 'mobile_capture_' . $type, 'S3', (int)($result['id'] ?? 0),
        ['s3.put_request' => 1, 's3.storage_bytes_delta' => $size],
        $started, ['size_bytes' => $size]
    );
    JsonResponse::send(['ok' => true, 'nombre' => $name, 'ruta' => $route]);
} catch (Throwable $e) {
    error_log('[mobile capture] ' . $e::class . ': ' . $e->getMessage());
    JsonResponse::error('No se pudo guardar el archivo.', 500);
}
