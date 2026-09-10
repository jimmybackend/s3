<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\ArcadeLinkService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\FederationPageRenderer;
use JsonException;
use Throwable;

final class FederationController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request,
        private FederationPageRenderer $renderer
    ) {
    }

    public function index(): void
    {
        $userId = $this->currentUserId();
        $inspected = null;
        $notice = null;
        $error = null;
        $node = null;
        try {
            $service = new FederationService($this->app);
            try {
                $node = $service->nodeDescriptor();
            } catch (Throwable) {
                $node = null;
            }
            if ($this->request->method() === 'POST') {
                $action = strtolower($this->request->postString('action'));
                if ($action === 'create') {
                    if ($userId <= 0) throw new FederationException('Debes iniciar sesión para crear un ArcadeLink.', 401);
                    $this->downloadCreatedLink($service, $userId);
                    return;
                }
                if ($action === 'inspect') {
                    $started = microtime(true);
                    $raw = $this->uploadedArcadeLink();
                    $inspected = $service->inspect($raw, $userId);
                    if ($userId > 0) {
                        $this->activity()->success($userId, 'arcadelink_resolve', 'FederationCloud', null, ['drive.no_direct_aws_charge' => 1], $started, [
                            'visibility' => (string)($inspected['resource']['visibility'] ?? ''),
                            'status' => (string)($inspected['resource']['status'] ?? ''),
                            'aws_direct' => false,
                        ]);
                    }
                    $notice = 'ArcadeLink válido: firma comprobada y resolución completada.';
                } elseif ($action === 'open') {
                    $started = microtime(true);
                    $raw = $this->request->postRawString('arcadelink_text');
                    if ($raw === '' || strlen($raw) > ArcadeLinkService::MAX_BYTES) {
                        throw new FederationException('ArcadeLink ausente o demasiado grande.');
                    }
                    $opened = $service->openLocal($raw, $userId);
                    if ($userId > 0) {
                        $this->activity()->success($userId, 'arcadelink_open', 'FederationCloud', (int)$opened['file_id'], ['drive.no_direct_aws_charge' => 1], $started, [
                            'aws_direct' => false,
                        ]);
                    }
                    header('Location: ' . $opened['url'], true, 302);
                    exit;
                } elseif ($action !== '') {
                    throw new FederationException('Acción FederationCloud no soportada.');
                }
            } elseif ($this->request->method() !== 'GET') {
                throw new FederationException('Método no permitido.', 405);
            }
        } catch (FederationException $e) {
            $error = $e->getMessage();
            if ($userId > 0) $this->activity()->failure($userId, 'arcadelink_resolve', 'FederationCloud');
        } catch (Throwable) {
            $error = 'FederationCloud no pudo completar la operación.';
            if ($userId > 0) $this->activity()->failure($userId, 'arcadelink_resolve', 'FederationCloud');
        }
        $this->renderer->render($userId, $inspected, $notice, $error, $node);
    }

    public function nodeApi(): void
    {
        try {
            $service = new FederationService($this->app);
            JsonResponse::send($service->nodeDescriptor());
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'Nodo FederationCloud no disponible.'], 500);
        }
    }

    public function resolveApi(): void
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        $length = (int)$this->request->serverString('CONTENT_LENGTH', '0');
        if ($length <= 0 || $length > ArcadeLinkService::MAX_BYTES) {
            JsonResponse::send(['ok' => false, 'error' => 'Payload ausente o demasiado grande.'], 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, ArcadeLinkService::MAX_BYTES + 1);
        if (!is_string($raw) || strlen($raw) > ArcadeLinkService::MAX_BYTES) {
            JsonResponse::send(['ok' => false, 'error' => 'Payload demasiado grande.'], 413);
        }
        try {
            $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($body) || !array_key_exists('arcadelink', $body)) {
                throw new FederationException('Solicitud de resolución inválida.');
            }
            $service = new FederationService($this->app);
            $resource = $service->resolveForOrigin($body['arcadelink'], 0);
            JsonResponse::send(['ok' => true, 'resource' => $resource]);
        } catch (JsonException) {
            JsonResponse::send(['ok' => false, 'error' => 'JSON inválido.'], 400);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo resolver el recurso.'], 500);
        }
    }

    private function downloadCreatedLink(FederationService $service, int $userId): void
    {
        $started = microtime(true);
        $fileId = $this->request->postInt('file_id');
        try {
            $created = $service->createLink(
                $userId,
                $fileId,
                $this->request->postString('visibility', 'PRIVATE'),
                $this->request->postString('rights', 'unknown_rights')
            );
            $this->activity()->success($userId, 'arcadelink_create', 'FederationCloud', (int)$created['file_id'], ['drive.no_direct_aws_charge' => 1], $started, [
                'visibility' => $this->request->postString('visibility', 'PRIVATE'),
                'rights' => $this->request->postString('rights', 'unknown_rights'),
                'aws_direct' => false,
            ]);
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$created['filename']) ?: 'resource.arcadelink';
            header('Content-Type: application/vnd.arcadecloud.arcadelink+json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen((string)$created['content']));
            echo $created['content'];
            exit;
        } catch (Throwable $e) {
            $this->activity()->failure($userId, 'arcadelink_create', 'FederationCloud', $started);
            if ($e instanceof FederationException) throw $e;
            throw new FederationException('No se pudo crear el ArcadeLink.', 500);
        }
    }

    private function uploadedArcadeLink(): string
    {
        $files = $this->request->files();
        $file = $files['arcadelink_file'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new FederationException('Selecciona un archivo .arcadelink válido.');
        }
        $name = (string)($file['name'] ?? '');
        $size = (int)($file['size'] ?? -1);
        $tmp = (string)($file['tmp_name'] ?? '');
        if (!preg_match('/\.arcadelink\z/i', $name) || $size <= 0 || $size > ArcadeLinkService::MAX_BYTES) {
            throw new FederationException('El archivo debe terminar en .arcadelink y medir como máximo 64 KiB.');
        }
        if ($tmp === '' || !is_uploaded_file($tmp) || !is_readable($tmp)) {
            throw new FederationException('No se pudo validar el archivo subido.');
        }
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = strtolower((string)$finfo->file($tmp));
            $allowed = ['application/json', 'text/plain', 'application/octet-stream', 'application/x-empty'];
            if ($mime !== '' && !in_array($mime, $allowed, true) && !str_ends_with($mime, '+json')) {
                throw new FederationException('MIME del ArcadeLink no permitido.');
            }
        }
        $raw = file_get_contents($tmp, false, null, 0, ArcadeLinkService::MAX_BYTES + 1);
        if (!is_string($raw) || strlen($raw) > ArcadeLinkService::MAX_BYTES) {
            throw new FederationException('No se pudo leer el ArcadeLink.');
        }
        return $raw;
    }

    private function currentUserId(): int
    {
        $session = $this->app->session();
        $session->start();
        return $session->isAuthenticated() ? $session->userId() : 0;
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }
}
