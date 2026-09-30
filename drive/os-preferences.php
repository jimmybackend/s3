<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Security\UserOsPreferencesRepository;

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
    $expected = (string)$session->get('upload_csrf', '');
    $sent = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
        JsonResponse::error('Token CSRF inválido.', 403);
    }
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        JsonResponse::error('Configuración inválida.', 400);
    }
    $preferences = [
        'wallpaper' => mb_substr((string)($payload['wallpaper'] ?? ''), 0, 4096),
        'wallpaperName' => mb_substr((string)($payload['wallpaperName'] ?? ''), 0, 255),
        'windowOpacity' => max(35, min(100, (int)($payload['windowOpacity'] ?? 94))),
        'menuOpacity' => max(35, min(100, (int)($payload['menuOpacity'] ?? 98))),
    ];
    (new UserOsPreferencesRepository($app->db()))->save($session->userId(), $preferences);
    JsonResponse::send(['ok' => true]);
} catch (Throwable $error) {
    error_log('[ArcadeCloud os-preferences] ' . $error->getMessage());
    JsonResponse::error('No se pudo guardar la configuración.', 500);
}
