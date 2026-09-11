<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationAccessService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use JsonException;
use Throwable;

final class FederationAccessController
{
    private const CSRF_SESSION_KEY = 'federation_access_csrf';

    public function __construct(private DriveApplication $app, private Request $request)
    {
    }

    public function userApi(): void
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated()) JsonResponse::send(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        $userId = $session->userId();
        try {
            $service = new FederationAccessService($this->app);
            if ($this->request->method() === 'GET') {
                JsonResponse::send([
                    'ok' => true,
                    'csrf' => $this->csrfToken(),
                    'incoming' => $service->inbox($userId),
                    'outgoing' => $service->outbox($userId),
                    'shares' => $service->shares($userId),
                ]);
            }
            if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
            $this->requireCsrf();
            $action = strtolower($this->request->postString('action'));
            if ($action === 'request') {
                JsonResponse::send($service->requestAccess($userId, $this->request->postString('resource_id')));
            }
            if ($action === 'decision') {
                $decision = strtolower($this->request->postString('decision'));
                if (!in_array($decision, ['approve','reject'], true)) throw new FederationException('Decisión inválida.', 400);
                JsonResponse::send($service->decide(
                    $userId,
                    $this->request->postString('request_id'),
                    $decision === 'approve',
                    $this->request->postInt('days', 7)
                ));
            }
            JsonResponse::send(['ok' => false, 'error' => 'Acción FederationCloud no permitida.'], 400);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo completar la operación de acceso FederationCloud.'], 500);
        }
    }

    public function receiveApi(): void
    {
        if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        try {
            $body = $this->jsonBody(65536);
            if (!is_array($body['request'] ?? null) || array_is_list($body['request'])) throw new FederationException('Solicitud firmada ausente.', 400);
            JsonResponse::send((new FederationAccessService($this->app))->receiveRequest($body['request']));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo recibir la solicitud FederationCloud.'], 500);
        }
    }

    public function statusApi(): void
    {
        if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        try {
            $body = $this->jsonBody(65536);
            if (!is_array($body['request'] ?? null) || array_is_list($body['request'])) throw new FederationException('Solicitud firmada ausente.', 400);
            JsonResponse::send((new FederationAccessService($this->app))->requestStatus($body['request']));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo consultar la solicitud FederationCloud.'], 500);
        }
    }

    private function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF_SESSION_KEY] ?? '';
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_SESSION_KEY] = $token;
        }
        return $token;
    }

    private function requireCsrf(): void
    {
        $expected = $this->csrfToken();
        $provided = $this->request->serverString('HTTP_X_FEDERATION_ACCESS_CSRF');
        if ($provided === '' || !hash_equals($expected, $provided)) throw new FederationException('CSRF FederationCloud inválido.', 403);
    }

    private function jsonBody(int $maxBytes): array
    {
        $length = (int)$this->request->serverString('CONTENT_LENGTH', '0');
        if ($length <= 0 || $length > $maxBytes) throw new FederationException('Payload ausente o demasiado grande.', 413);
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (!is_string($raw) || strlen($raw) > $maxBytes) throw new FederationException('Payload demasiado grande.', 413);
        try {
            $decoded = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('JSON FederationCloud inválido.', 400);
        }
        if (!is_array($decoded) || array_is_list($decoded)) throw new FederationException('Payload debe ser objeto JSON.', 400);
        return $decoded;
    }
}
