<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class UserProfileController
{
    public function __construct(private DriveApplication $app, private Request $request)
    {
    }

    public function api(): void
    {
        $session = $this->app->session();
        $session->start();

        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::send(['ok' => false, 'error' => 'Autenticación requerida.'], 401);
        }

        $userId = $session->userId();

        try {
            if ($this->request->method() === 'GET') {
                JsonResponse::send([
                    'ok' => true,
                    'profile' => $this->app->userProfileService()->profile($userId),
                ]);
            }

            if ($this->request->method() !== 'POST') {
                JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
            }

            $this->requireCsrf();
            $action = $this->request->postString('action');

            if ($action === 'update_personal') {
                JsonResponse::send([
                    'ok' => true,
                    'message' => 'Perfil actualizado correctamente.',
                    'profile' => $this->app->userProfileService()->updatePersonal(
                        $userId,
                        $this->request->allPost()
                    ),
                ]);
            }

            if ($action === 'upload_avatar') {
                $files = $this->request->files();
                $file = $files['profilepicture'] ?? null;
                if (!is_array($file)) {
                    throw new InvalidArgumentException('Selecciona una imagen para el perfil.');
                }

                JsonResponse::send([
                    'ok' => true,
                    'message' => 'Imagen de perfil actualizada.',
                    'profile' => $this->app->userProfileService()->uploadAvatar($userId, $file),
                ]);
            }

            if ($action === 'remove_avatar') {
                JsonResponse::send([
                    'ok' => true,
                    'message' => 'Imagen eliminada. Se volverán a mostrar tus iniciales.',
                    'profile' => $this->app->userProfileService()->removeAvatar($userId),
                ]);
            }

            if ($action === 'request_password_code') {
                JsonResponse::send(
                    $this->app->passwordChangeService()->requestCode(
                        $userId,
                        $this->request->serverString('HTTP_HOST')
                    )
                );
            }

            if ($action === 'change_password') {
                JsonResponse::send(
                    $this->app->passwordChangeService()->changePassword(
                        $userId,
                        $this->request->postString('verification_code'),
                        $this->request->postRawString('new_password'),
                        $this->request->postRawString('confirm_password')
                    )
                );
            }

            JsonResponse::send(['ok' => false, 'error' => 'Acción de perfil no reconocida.'], 400);
        } catch (InvalidArgumentException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 503);
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo completar la operación del perfil.'], 500);
        }
    }

    private function requireCsrf(): void
    {
        $expected = (string)$this->app->session()->get('profile_csrf', '');
        $sent = $this->request->serverString('HTTP_X_PROFILE_CSRF');

        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido. Recarga el Drive.'], 403);
        }
    }
}
