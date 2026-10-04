<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Security\OsPreferenceNodeResolver;
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
    $patch = [];
    if (isset($payload['windowPreference']) && is_array($payload['windowPreference'])) {
        $windowPreference = $payload['windowPreference'];
        $appKey = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($windowPreference['app'] ?? '')));
        if ($appKey === '') {
            JsonResponse::error('Tipo de ventana inválido.', 400);
        }
        $patch['windowPreferences'] = [
            $appKey => [
                'width' => max(240, min(2400, (int)($windowPreference['width'] ?? 0))),
                'height' => max(180, min(1600, (int)($windowPreference['height'] ?? 0))),
            ],
        ];
    } else {
        $patch = [
        'theme' => in_array(($payload['theme'] ?? ''), ['light', 'dark'], true)
            ? (string)$payload['theme']
            : 'dark',
        'wallpaper' => mb_substr((string)($payload['wallpaper'] ?? ''), 0, 4096),
        'wallpaperName' => mb_substr((string)($payload['wallpaperName'] ?? ''), 0, 255),
        'wallpaperEnabled' => filter_var(
            $payload['wallpaperEnabled'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? true,
        'windowOpacity' => max(35, min(100, (int)($payload['windowOpacity'] ?? 94))),
        'menuOpacity' => max(35, min(100, (int)($payload['menuOpacity'] ?? 98))),
        ];
    }
    $repository = new UserOsPreferencesRepository($app->db());
    $nodeKey = OsPreferenceNodeResolver::resolve();
    $preferences = array_replace_recursive($repository->find($session->userId(), $nodeKey), $patch);
    $repository->save($session->userId(), $preferences, $nodeKey);
    JsonResponse::send(['ok' => true]);
} catch (Throwable $error) {
    error_log('[ArcadeCloud os-preferences] ' . $error->getMessage());
    JsonResponse::error('No se pudo guardar la configuración.', 500);
}
