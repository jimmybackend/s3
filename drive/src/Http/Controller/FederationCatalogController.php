<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationCatalogService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationGossipService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use JsonException;
use Throwable;

final class FederationCatalogController
{
    public function __construct(private DriveApplication $app, private Request $request)
    {
    }

    public function searchApi(): void
    {
        if ($this->request->method() !== 'GET') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        if (!$this->authenticated()) {
            JsonResponse::send(['ok' => false, 'error' => 'Debes iniciar sesión para usar la búsqueda global.'], 401);
        }
        try {
            $query = $this->request->queryString('q');
            $limit = (int)$this->request->queryString('limit', '20');
            $service = new FederationCatalogService($this->app);
            JsonResponse::send([
                'ok' => true,
                'query' => $query,
                'results' => $service->search($query, $limit),
            ]);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo consultar el catálogo FederationCloud.'], 500);
        }
    }

    public function resourceApi(): void
    {
        if ($this->request->method() !== 'GET') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        if (!$this->authenticated()) {
            JsonResponse::send(['ok' => false, 'error' => 'Debes iniciar sesión para consultar el recurso global.'], 401);
        }
        try {
            $resourceId = $this->request->queryString('resource_id');
            if (!preg_match('/\Aarl_[A-Za-z0-9_-]{16,80}\z/', $resourceId)) {
                throw new FederationException('Resource ID inválido.', 400);
            }
            $resource = (new FederationCatalogService($this->app))->resource($resourceId);
            if ($resource === null) JsonResponse::send(['ok' => false, 'error' => 'Recurso no encontrado.'], 404);
            JsonResponse::send(['ok' => true, 'resource' => $resource]);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo consultar el recurso FederationCloud.'], 500);
        }
    }

    public function syncPullApi(): void
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        try {
            $body = $this->jsonBody(65536);
            $clock = $body['clock'] ?? [];
            if (!is_array($clock) || array_is_list($clock)) throw new FederationException('Reloj federado inválido.', 400);
            $limit = max(1, min(50, (int)($body['limit'] ?? 25)));
            JsonResponse::send((new FederationCatalogService($this->app))->pull($clock, $limit));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo exportar el bloque federado.'], 500);
        }
    }

    public function syncPushApi(): void
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        try {
            $body = $this->jsonBody(262144);
            $events = $body['events'] ?? null;
            if (!is_array($events) || count($events) > 50) throw new FederationException('Lote de eventos federados inválido.', 400);
            JsonResponse::send((new FederationCatalogService($this->app))->push($events));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo importar el bloque federado.'], 500);
        }
    }

    public function syncStatusApi(): void
    {
        if ($this->request->method() !== 'GET') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || !$session->isSuperAdmin()) {
            JsonResponse::send(['ok' => false, 'error' => 'Sólo superadmin puede consultar el estado de sincronización.'], 403);
        }
        try {
            JsonResponse::send((new FederationGossipService($this->app))->status());
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo consultar estado gossip.'], 500);
        }
    }

    private function authenticated(): bool
    {
        $session = $this->app->session();
        $session->start();
        return $session->isAuthenticated();
    }

    private function jsonBody(int $maxBytes): array
    {
        $length = (int)$this->request->serverString('CONTENT_LENGTH', '0');
        if ($length <= 0 || $length > $maxBytes) throw new FederationException('Payload federado ausente o demasiado grande.', 413);
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (!is_string($raw) || strlen($raw) > $maxBytes) throw new FederationException('Payload federado demasiado grande.', 413);
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('JSON federado inválido.', 400);
        }
        if (!is_array($decoded) || array_is_list($decoded)) throw new FederationException('Payload federado debe ser objeto JSON.', 400);
        return $decoded;
    }
}
