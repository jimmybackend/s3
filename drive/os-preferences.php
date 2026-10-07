<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Security\Drive3dPreferenceSanitizer;
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
                'left' => max(-4000, min(4000, (int)($windowPreference['left'] ?? 0))),
                'top' => max(0, min(4000, (int)($windowPreference['top'] ?? 0))),
                'width' => max(240, min(2400, (int)($windowPreference['width'] ?? 0))),
                'height' => max(180, min(1600, (int)($windowPreference['height'] ?? 0))),
            ],
        ];
    } elseif (isset($payload['drive3dPreference']) && is_array($payload['drive3dPreference'])) {
        $patch['drive3d'] = (new Drive3dPreferenceSanitizer())->sanitize($payload['drive3dPreference']);
    } elseif (isset($payload['mediaPlayerPreference']) && is_array($payload['mediaPlayerPreference'])) {
        $mediaPlayer = $payload['mediaPlayerPreference'];
        $geometry = [];
        foreach (['desktop', 'tablet', 'mobile'] as $mode) {
            if (!isset($mediaPlayer['geometry'][$mode]) || !is_array($mediaPlayer['geometry'][$mode])) {
                continue;
            }
            $value = $mediaPlayer['geometry'][$mode];
            $geometry[$mode] = [
                'left' => max(-4000, min(4000, (int)($value['left'] ?? 0))),
                'top' => max(0, min(4000, (int)($value['top'] ?? 0))),
                'width' => max(240, min(2400, (int)($value['width'] ?? 0))),
            ];
        }
        $patch['mediaPlayerPreferences'] = [
            'pinned' => filter_var(
                $mediaPlayer['pinned'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? true,
            'videoMode' => ($mediaPlayer['videoMode'] ?? '') === 'screen' ? 'screen' : 'cloud',
            'geometry' => $geometry,
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
        'windowOpacity' => max(0, min(100, (int)($payload['windowOpacity'] ?? 94))),
        'menuOpacity' => max(0, min(100, (int)($payload['menuOpacity'] ?? 98))),
        'chromeOpacity' => max(0, min(100, (int)($payload['chromeOpacity'] ?? 96))),
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
