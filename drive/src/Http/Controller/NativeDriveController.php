<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\Http\Controller;
use ArcadeCloud\Drive\Application\NativeDriveService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Security\LoginRateLimiter;

/** JSON adapter over the existing authentication, catalog and access services. */
final class NativeDriveController extends AbstractJsonController
{
    public function dispatch(): never
    {
        header('Cache-Control: no-store');
        try {
            $session = $this->app->session(); $session->start();
            $action = $this->request->queryString('action', 'session');
            if ($action === 'bootstrap') {
                $this->getOnly();
                if ((string)$session->get('upload_csrf', '') === '') $session->set('upload_csrf', bin2hex(random_bytes(32)));
                JsonResponse::send(['ok' => true, 'csrf' => $session->get('upload_csrf')]);
            }
            if ($action === 'login') {
                $this->requirePost(); $this->requireDriveCsrf();
                $email = $this->request->postString('email'); $ip = $this->request->serverString('REMOTE_ADDR');
                $limiter = new LoginRateLimiter();
                if (!$limiter->allow($email, $ip)) JsonResponse::error('Demasiados intentos. Espera antes de volver a intentar.', 429);
                if ($email === '' || preg_match("/[',\s%()]/", $email)) JsonResponse::error('Credenciales inválidas.', 401);
                $result = $this->app->authenticationService()->authenticate($email, $this->request->postRawString('password'), $ip);
                if (($result['status'] ?? '') !== 'authenticated') {
                    $limiter->registerFailure($email, $ip);
                    JsonResponse::error('Credenciales inválidas o cuenta inactiva.', 401);
                }
                $limiter->clear($email, $ip);
                $session->set('upload_csrf', bin2hex(random_bytes(32)));
                $session->set('native_issued_at', time());
                $session->set('native_last_seen', time());
                $account = $this->app->authenticationRepository()->findUserByEmail($email);
                $session->set('native_credential_fingerprint', hash('sha256', (string)$account['password']));
                JsonResponse::send(['ok' => true] + $this->identity());
            }
            $userId = $this->guardAuthenticated();
            // Recheck actual account state. Server-side session destruction revokes the cookie.
            $user = $this->app->authenticationRepository()->findUserByEmail($session->userName());
            if (!$user || (int)$user['id'] !== $userId || $user['userstatus'] !== 'Activo'
                || !hash_equals((string)$session->get('native_credential_fingerprint', ''), hash('sha256', (string)$user['password']))) {
                $session->destroy(); JsonResponse::error('Sesión revocada.', 401);
            }
            if ($action === 'logout') {
                $this->requirePost(); $this->requireDriveCsrf(); $session->destroy();
                JsonResponse::send(['ok' => true]);
            }
            $this->getOnly();
            if ($action === 'session') JsonResponse::send(['ok' => true] + $this->identity());
            if ($action === 'browse') {
                $data = (new NativeDriveService($this->app))->browse($userId, $this->request->allQuery());
                JsonResponse::send(['ok' => true] + $data);
            }
            if ($action === 'file') {
                $row = $this->app->fileRecordLocator()->requireReadableByKey($userId, $this->request->queryString('key'));
                JsonResponse::send(['ok' => true, 'file' => \ArcadeCloud\Drive\Application\NativeDrivePolicy::file($row)]);
            }
            if ($action === 'download') {
                $service = new NativeDriveService($this->app);
                // Existing locator rejects protected files and files belonging to other users.
                JsonResponse::send(['ok' => true] + $service->signedDownload($userId, $this->request->queryString('key')));
            }
            JsonResponse::error('Operación desconocida.', 404);
        } catch (\Throwable $error) {
            $status = in_array($error->getCode(), [401,403,404,423], true) ? $error->getCode() : 500;
            $this->fail($error, $status);
        }
    }
    private function getOnly(): void
    {
        if ($this->request->method() !== 'GET') JsonResponse::error('Método no permitido.', 405);
    }
    private function identity(): array
    {
        $s = $this->app->session();
        return ['user_id' => $s->userId(), 'alias' => \ArcadeCloud\Drive\View\UserIdentityPresenter::alias($s->userName()),
            'root' => $this->app->userStoragePath()->rootForUser($s->userId()), 'csrf' => $s->get('upload_csrf')];
    }
}
