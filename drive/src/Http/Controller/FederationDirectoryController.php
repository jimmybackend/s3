<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\ArcadeLinkService;
use ArcadeCloud\Drive\Federation\FederationDirectoryService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use JsonException;
use Throwable;

final class FederationDirectoryController
{
    public function __construct(private DriveApplication $app, private Request $request) {}

    public function nodesApi(): void
    {
        if ($this->request->method() !== 'GET') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        try {
            JsonResponse::send((new FederationDirectoryService($this->app))->directory());
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'Directorio FederationCloud no disponible.'], 500);
        }
    }

    public function nodeNameAvailabilityApi(): void
    {
        if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        $contentType = strtolower(trim($this->request->serverString('CONTENT_TYPE')));
        if ($contentType !== '' && !str_starts_with($contentType, 'application/json')) {
            JsonResponse::send(['ok' => false, 'error' => 'Content-Type debe ser application/json.'], 415);
        }
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 4096) {
            JsonResponse::send(['ok' => false, 'error' => 'Payload inválido.'], 413);
        }
        try {
            $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) throw new FederationException('JSON inválido.', 400);
            JsonResponse::send((new FederationDirectoryService($this->app))->nodeNameAvailability((string)($body['node_name'] ?? '')));
        } catch (JsonException) {
            JsonResponse::send(['ok' => false, 'error' => 'JSON inválido.'], 400);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo validar la disponibilidad del nombre.'], 500);
        }
    }

    public function registerApi(): void
    {
        if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
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
            if (!is_array($body) || !is_array($body['descriptor'] ?? null) || array_is_list($body['descriptor'])) {
                throw new FederationException('Se requiere un descriptor FederationCloud firmado.', 400);
            }
            JsonResponse::send((new FederationDirectoryService($this->app))->registerRemote($body['descriptor']));
        } catch (JsonException) {
            JsonResponse::send(['ok' => false, 'error' => 'JSON inválido.'], 400);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo registrar el nodo FederationCloud.'], 500);
        }
    }
}
