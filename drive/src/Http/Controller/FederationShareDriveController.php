<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationShareDriveService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Throwable;

final class FederationShareDriveController
{
    private const CSRF_SESSION_KEY = 'federation_share_drive_csrf';

    public function __construct(private DriveApplication $app, private Request $request)
    {
    }

    public function handle(): void
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::send(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        }
        $userId = $session->userId();

        try {
            $service = new FederationShareDriveService($this->app);
            if ($this->request->method() === 'GET') {
                JsonResponse::send($service->state($userId) + ['csrf' => $this->csrfToken()]);
            }
            if ($this->request->method() !== 'POST') {
                JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
            }
            $this->requireCsrf();
            $action = strtolower($this->request->postString('action', 'import'));
            if ($action !== 'import') throw new FederationException('Acción de Compartidos no permitida.', 400);
            JsonResponse::send($service->queueImport($userId, $this->request->postString('share_id')));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo administrar el archivo compartido.'], 500);
        }
    }

    private function csrfToken(): string
    {
        $session = $this->app->session();
        $token = (string)$session->get(self::CSRF_SESSION_KEY, '');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $token = bin2hex(random_bytes(32));
            $session->set(self::CSRF_SESSION_KEY, $token);
        }
        return $token;
    }

    private function requireCsrf(): void
    {
        $expected = $this->csrfToken();
        $provided = $this->request->serverString('HTTP_X_FEDERATION_SHARE_DRIVE_CSRF');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new FederationException('CSRF de Compartidos inválido.', 403);
        }
    }
}