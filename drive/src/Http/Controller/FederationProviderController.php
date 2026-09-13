<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\ArcadeLinkService;
use ArcadeCloud\Drive\Federation\FederationCustomsService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationProviderAuthorizationService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use JsonException;
use Throwable;

final class FederationProviderController
{
    public function __construct(private DriveApplication $app, private Request $request)
    {
    }

    /**
     * Esta ruta queda reservada para confianza privilegiada: una copia/réplica
     * que servirá recursos del nodo origen. Los nodos independientes NO pasan
     * por Solicitudes; se registran automáticamente por register.php.
     */
    public function requestApi(): void
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }

        $contentType = strtolower(trim($this->request->serverString('CONTENT_TYPE')));
        if ($contentType !== '' && !str_starts_with($contentType, 'application/json')) {
            JsonResponse::send(['ok' => false, 'error' => 'Content-Type debe ser application/json.'], 415);
        }

        $length = (int)$this->request->serverString('CONTENT_LENGTH', '0');
        if ($length <= 0 || $length > ArcadeLinkService::MAX_BYTES) {
            JsonResponse::send(['ok' => false, 'error' => 'Payload ausente o demasiado grande.'], 413);
        }

        $raw = file_get_contents('php://input', false, null, 0, ArcadeLinkService::MAX_BYTES + 1);
        if (!is_string($raw) || $raw === '' || strlen($raw) > ArcadeLinkService::MAX_BYTES) {
            JsonResponse::send(['ok' => false, 'error' => 'Payload FederationCloud inválido.'], 413);
        }

        try {
            $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($body)
                || !is_array($body['provider_descriptor'] ?? null)
                || array_is_list($body['provider_descriptor'])) {
                throw new FederationException('Se requiere el descriptor firmado de la copia FederationCloud.', 400);
            }
            $relationship = strtolower(trim((string)($body['relationship'] ?? 'shared_backend')));
            if ($relationship !== 'shared_backend') {
                throw new FederationException(
                    'Los nodos independientes no requieren aprobación; deben anunciarse mediante register.php.',
                    400
                );
            }

            $result = (new FederationCustomsService($this->app))->enqueueSharedBackendAuthorization(
                (string)($body['origin_node_id'] ?? ''),
                $body['provider_descriptor'],
                (string)($body['role'] ?? 'mirror'),
                (string)($body['scope'] ?? 'all_allowed_resources'),
                is_string($body['request_id'] ?? null) ? (string)$body['request_id'] : null
            );
            JsonResponse::send($result, 202);
        } catch (JsonException) {
            JsonResponse::send(['ok' => false, 'error' => 'JSON inválido.'], 400);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo recibir la solicitud de backend compartido.'], 500);
        }
    }

    /**
     * Fast path de presencia para una réplica YA autorizada. No concede permisos,
     * no crea una Solicitud y no modifica role/scope: sólo verifica identidad,
     * endpoint HTTPS y actualiza disponibilidad/LastSeen.
     */
    public function presenceApi(): void
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        $contentType = strtolower(trim($this->request->serverString('CONTENT_TYPE')));
        if ($contentType !== '' && !str_starts_with($contentType, 'application/json')) {
            JsonResponse::send(['ok' => false, 'error' => 'Content-Type debe ser application/json.'], 415);
        }
        $length = (int)$this->request->serverString('CONTENT_LENGTH', '0');
        if ($length <= 0 || $length > ArcadeLinkService::MAX_BYTES) {
            JsonResponse::send(['ok' => false, 'error' => 'Payload ausente o demasiado grande.'], 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, ArcadeLinkService::MAX_BYTES + 1);
        if (!is_string($raw) || $raw === '' || strlen($raw) > ArcadeLinkService::MAX_BYTES) {
            JsonResponse::send(['ok' => false, 'error' => 'Payload FederationCloud inválido.'], 413);
        }

        try {
            $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($body)
                || !is_array($body['provider_descriptor'] ?? null)
                || array_is_list($body['provider_descriptor'])) {
                throw new FederationException('Se requiere el descriptor firmado de la réplica.', 400);
            }
            if (strtolower(trim((string)($body['relationship'] ?? ''))) !== 'shared_backend') {
                throw new FederationException('La puerta de presencia sólo acepta réplicas shared_backend.', 400);
            }

            $result = (new FederationProviderAuthorizationService($this->app))->receivePresence(
                (string)($body['origin_node_id'] ?? ''),
                $body['provider_descriptor']
            );
            JsonResponse::send($result, 200);
        } catch (JsonException) {
            JsonResponse::send(['ok' => false, 'error' => 'JSON inválido.'], 400);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo actualizar la presencia de la réplica.'], 500);
        }
    }

    public function providersApi(): void
    {
        if ($this->request->method() !== 'GET') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        try {
            JsonResponse::send((new FederationProviderAuthorizationService($this->app))->publicProviders());
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo consultar los proveedores autorizados.'], 500);
        }
    }

    public function adminApi(): void
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated()) {
            JsonResponse::send(['ok' => false, 'error' => 'Autenticación requerida.'], 401);
        }
        if (!$session->isSuperAdmin()) {
            JsonResponse::send(['ok' => false, 'error' => 'Sólo un superusuario puede autorizar copias con backend compartido.'], 403);
        }

        try {
            $service = new FederationProviderAuthorizationService($this->app);
            if ($this->request->method() === 'GET') {
                JsonResponse::send($service->adminState());
            }
            if ($this->request->method() !== 'POST') {
                JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
            }

            $expectedToken = (string)$session->get('federation_provider_csrf', '');
            $sentToken = $this->request->serverString('HTTP_X_FEDERATION_CSRF');
            if ($expectedToken === '' || $sentToken === '' || !hash_equals($expectedToken, $sentToken)) {
                JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido. Recarga el Drive.'], 403);
            }

            $providerNodeId = $this->request->postString('provider_node_id');
            $decision = $this->request->postString('decision');
            JsonResponse::send($service->decide($providerNodeId, $decision));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo administrar la solicitud de copia compartida.'], 500);
        }
    }
}
