<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationReplicaResolverService;
use ArcadeCloud\Drive\Federation\FederationReplicaService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use JsonException;
use Throwable;

final class FederationReplicaController
{
    public function __construct(private DriveApplication $app, private Request $request) {}

    public function offerApi(): void
    {
        if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        try {
            $body = $this->jsonBody(65536);
            $offer = $body['offer'] ?? null;
            if (!is_array($offer) || array_is_list($offer)) throw new FederationException('Oferta de réplica inválida.', 400);
            JsonResponse::send((new FederationReplicaService($this->app))->receiveOffer($offer));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo recibir la oferta de réplica.'], 500);
        }
    }

    public function userApi(): void
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated()) JsonResponse::send(['ok' => false, 'error' => 'Autenticación requerida.'], 401);
        $userId = $session->userId();
        if ($userId <= 0) JsonResponse::send(['ok' => false, 'error' => 'Sesión de usuario inválida.'], 401);

        try {
            $service = new FederationReplicaService($this->app);
            if ($this->request->method() === 'GET') {
                $csrf = (string)$session->get('federation_replica_csrf', '');
                if ($csrf === '') {
                    $csrf = bin2hex(random_bytes(24));
                    $session->set('federation_replica_csrf', $csrf);
                }
                JsonResponse::send($service->jobsForUser($userId) + ['csrf' => $csrf]);
            }
            if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
            $expected = (string)$session->get('federation_replica_csrf', '');
            $sent = $this->request->serverString('HTTP_X_FEDERATION_REPLICA_CSRF');
            if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
                JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido.'], 403);
            }
            $action = strtolower(trim($this->request->postString('action', 'queue')));
            if ($action !== 'queue') throw new FederationException('Acción de réplica no permitida.', 400);
            JsonResponse::send($service->queueForResource(
                $userId,
                $this->request->postString('resource_id'),
                max(1, min(3, $this->request->postInt('copies', 2)))
            ));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo administrar la réplica FederationCloud.'], 500);
        }
    }

    /** Máquina a máquina: sólo PUBLIC + copy_allowed. */
    public function resolveApi(): void
    {
        if ($this->request->method() !== 'POST') JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        try {
            $body = $this->jsonBody(8192);
            $resourceId = trim((string)($body['resource_id'] ?? ''));
            JsonResponse::send((new FederationReplicaResolverService($this->app))->publicLocation($resourceId));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo resolver la ubicación pública.'], 500);
        }
    }

    /** Usuario local o anónimo: ensambla desde hasta cuatro fuentes y transmite el archivo verificado. */
    public function openPreferred(): void
    {
        if ($this->request->method() !== 'GET') {
            http_response_code(405);
            echo 'Método no permitido.';
            return;
        }

        $path = null;
        try {
            $resourceId = $this->request->queryString('resource_id');
            $result = (new FederationReplicaResolverService($this->app))->downloadPublic($resourceId);
            $path = (string)($result['path'] ?? '');
            $expected = (int)($result['size_bytes'] ?? -1);
            if ($path === '' || !is_file($path) || $expected < 0 || (int)filesize($path) !== $expected) {
                throw new FederationException('La descarga federada verificada no quedó disponible.', 500);
            }

            $filename = $this->safeDownloadFilename((string)($result['title'] ?? 'archivo'));
            $mediaType = trim((string)($result['media_type'] ?? 'application/octet-stream'));
            if ($mediaType === '' || preg_match('/[\r\n]/', $mediaType)) $mediaType = 'application/octet-stream';

            header('Content-Type: ' . $mediaType);
            header('Content-Length: ' . $expected);
            header('Content-Disposition: attachment; filename="' . $this->asciiFilename($filename)
                . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            header('X-ArcadeCloud-Transport: ' . (!empty($result['parallel']) ? 'multisource' : 'single-source-proxy'));

            $fh = fopen($path, 'rb');
            if (!is_resource($fh)) throw new FederationException('No se pudo abrir la descarga federada.', 500);

            $sent = 0;
            $complete = true;
            try {
                while (!feof($fh)) {
                    $chunk = fread($fh, 1024 * 1024);
                    if ($chunk === false) {
                        $complete = false;
                        break;
                    }
                    if ($chunk === '') continue;
                    echo $chunk;
                    $sent += strlen($chunk);
                    if (function_exists('ob_flush')) @ob_flush();
                    flush();
                    if (connection_aborted()) {
                        $complete = false;
                        break;
                    }
                }
            } finally {
                fclose($fh);
            }

            if ($complete && $sent === $expected && connection_status() === CONNECTION_NORMAL) {
                try {
                    (new \ArcadeCloud\Drive\Federation\FederationResourceDeliveryService($this->app))->recordCompleted(
                        $resourceId,
                        (array)($result['sources_used'] ?? []),
                        $sent,
                        !empty($result['parallel'])
                    );
                } catch (Throwable $historyError) {
                    error_log('[FederationCloud delivery history] ' . $historyError->getMessage());
                }
            }
            return;
        } catch (FederationException $e) {
            if (!headers_sent()) {
                http_response_code($e->httpStatus());
                $internal = $e->getMessage();
                $safeDiagnosticPrefix = 'No fue posible obtener el archivo desde los nodos disponibles. Diagnóstico FederationCloud:';
                $message = $e->httpStatus() >= 500
                    ? (str_starts_with($internal, $safeDiagnosticPrefix)
                        ? $internal
                        : 'No fue posible obtener el archivo desde los nodos disponibles.')
                    : $internal;
                echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        } catch (Throwable) {
            if (!headers_sent()) {
                http_response_code(500);
                echo 'No fue posible obtener el archivo desde los nodos disponibles.';
            }
        } finally {
            if (is_string($path) && $path !== '') @unlink($path);
        }
    }

    private function safeDownloadFilename(string $name): string
    {
        $name = trim(str_replace(['\\', '/'], '-', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? $name;
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '') $name = 'archivo-federado';
        if (function_exists('mb_substr')) return mb_substr($name, 0, 220);
        return substr($name, 0, 220);
    }

    private function asciiFilename(string $name): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?? 'archivo-federado';
        $ascii = trim(str_replace(['"', '\\'], '_', $ascii), " .");
        return $ascii !== '' ? substr($ascii, 0, 180) : 'archivo-federado';
    }

    private function jsonBody(int $maxBytes): array
    {
        $length = (int)$this->request->serverString('CONTENT_LENGTH', '0');
        if ($length <= 0 || $length > $maxBytes) throw new FederationException('Payload de réplica ausente o demasiado grande.', 413);
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (!is_string($raw) || strlen($raw) > $maxBytes) throw new FederationException('Payload de réplica demasiado grande.', 413);
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FederationException('JSON de réplica inválido.', 400);
        }
        if (!is_array($decoded) || array_is_list($decoded)) throw new FederationException('Payload de réplica debe ser objeto JSON.', 400);
        return $decoded;
    }
}
