<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationDropConfig;
use ArcadeCloud\Drive\Federation\FederationDropIngressService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use JsonException;
use Throwable;

final class FederationDropIngressController
{
    public function __construct(private DriveApplication $app, private Request $request)
    {
    }

    public function api(): never
    {
        $this->cors();
        if ($this->request->method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $service = new FederationDropIngressService($this->app);
        $action = strtolower(trim($this->request->queryString('action', '')));

        try {
            if ($action === 'probe' && $this->request->method() === 'GET') {
                header('Cache-Control: no-store');
                JsonResponse::send($service->remoteProbe());
            }

            if ($this->request->method() !== 'POST') {
                JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
            }

            $body = $this->jsonBody(65536);
            if ($action === '') {
                $action = strtolower(trim((string)($body['action'] ?? '')));
            }
            $grant = $body['grant'] ?? null;
            if (!is_array($grant) || array_is_list($grant)) {
                throw new FederationException('Grant FederationDrop ingress ausente.', 400);
            }

            $result = match ($action) {
                'authorize' => $service->remoteAuthorize($grant),
                'complete' => $service->remoteComplete($grant),
                'source' => $service->remoteSource($grant),
                'delete' => $service->remoteDelete($grant),
                default => throw new FederationException('Acción FederationDrop ingress no soportada.', 404),
            };
            header('Cache-Control: no-store');
            JsonResponse::send($result);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable $e) {
            error_log('[FederationDrop ingress] ' . $e->getMessage());
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo completar la operación ingress.'], 500);
        }
    }

    private function cors(): void
    {
        $commerce = FederationDropConfig::fromEnvironment()->commerceUrl;
        $parts = parse_url($commerce);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $allowedOrigin = ($scheme !== '' && $host !== '') ? $scheme . '://' . $host . $port : '';

        $origin = trim($this->request->serverString('HTTP_ORIGIN'));
        if ($origin !== '' && $allowedOrigin !== '' && hash_equals($allowedOrigin, $origin)) {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 600');
        }
    }

    private function jsonBody(int $maxBytes): array
    {
        $length = (int)$this->request->serverString('CONTENT_LENGTH', '0');
        if ($length <= 0 || $length > $maxBytes) {
            throw new FederationException('Payload ingress ausente o demasiado grande.', 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (!is_string($raw) || $raw === '' || strlen($raw) > $maxBytes) {
            throw new FederationException('Payload ingress inválido.', 413);
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('JSON ingress inválido.', 400);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new FederationException('Payload ingress debe ser objeto JSON.', 400);
        }
        return $decoded;
    }
}
