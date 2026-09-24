<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\ArcadeLinkService;
use ArcadeCloud\Drive\Federation\FederationDropService;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\FederationDropPageRenderer;
use Throwable;

final class FederationDropController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function page(): void
    {
        $service = new FederationDropService($this->app);
        (new FederationDropPageRenderer())->render(
            $service->publicState(),
            $this->request->queryString('source'),
            $this->request->queryString('manage'),
            $this->request->queryString('owner_token'),
            $this->request->queryString('payment_return')
        );
    }

    public function api(): never
    {
        $action = strtolower($this->request->queryString('action'));
        $service = new FederationDropService($this->app);

        try {
            if ($action === 'state' && $this->request->method() === 'GET') {
                JsonResponse::send(['ok' => true] + $service->publicState());
            }

            if ($action === 'quote' && $this->request->method() === 'POST') {
                JsonResponse::send([
                    'ok' => true,
                    'quote' => $service->quote(
                        $this->request->postInt('size_bytes'),
                        $this->request->postInt('days'),
                        $this->request->postInt('downloads')
                    ),
                ]);
            }

            if ($action === 'create' && $this->request->method() === 'POST') {
                JsonResponse::send($service->createOrder(
                    $this->request->postString('email'),
                    $this->request->postString('filename'),
                    $this->request->postInt('size_bytes'),
                    $this->request->postString('mime_type', 'application/octet-stream'),
                    $this->request->postInt('days'),
                    $this->request->postInt('downloads'),
                    $this->request->postString('source_domain')
                ), 201);
            }

            if ($action === 'upload-authorize' && $this->request->method() === 'POST') {
                JsonResponse::send($service->authorizeUpload(
                    $this->request->postString('drop_id'),
                    $this->request->postString('owner_token')
                ));
            }

            if ($action === 'upload-complete' && $this->request->method() === 'POST') {
                JsonResponse::send($service->completeUpload(
                    $this->request->postString('drop_id'),
                    $this->request->postString('owner_token')
                ));
            }

            if ($action === 'status' && $this->request->method() === 'GET') {
                JsonResponse::send($service->ownerStatus(
                    $this->request->queryString('drop_id'),
                    $this->request->queryString('owner_token')
                ));
            }

            if ($action === 'delete' && $this->request->method() === 'POST') {
                JsonResponse::send($service->deleteOwned(
                    $this->request->postString('drop_id'),
                    $this->request->postString('owner_token')
                ));
            }

            if ($action === 'payment-webhook' && $this->request->method() === 'POST') {
                $length = (int)$this->request->serverString('CONTENT_LENGTH', '0');
                if ($length <= 0 || $length > 65536) {
                    JsonResponse::send(['ok' => false, 'error' => 'Webhook ausente o demasiado grande.'], 413);
                }
                $raw = file_get_contents('php://input', false, null, 0, 65537);
                if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) {
                    JsonResponse::send(['ok' => false, 'error' => 'Webhook FederationDrop inválido.'], 413);
                }
                JsonResponse::send($service->receivePaymentWebhook(
                    $raw,
                    $this->request->serverString('HTTP_X_ARCADECLOUD_DROP_SIGNATURE')
                ));
            }

            JsonResponse::send(['ok' => false, 'error' => 'Acción FederationDrop no soportada.'], 405);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable $e) {
            error_log('[FederationDrop] ' . $e->getMessage());
            JsonResponse::send(['ok' => false, 'error' => 'FederationDrop no pudo completar la operación.'], 500);
        }
    }

    public function download(): never
    {
        try {
            $url = (new FederationDropService($this->app))->downloadUrl(
                $this->request->queryString('id'),
                $this->request->queryString('t')
            );
            header('Cache-Control: no-store');
            header('Location: ' . $url, true, 302);
            exit;
        } catch (FederationException $e) {
            http_response_code($e->httpStatus());
            header('Content-Type: text/plain; charset=UTF-8');
            echo $e->getMessage();
            exit;
        } catch (Throwable) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'No se pudo descargar FederationDrop.';
            exit;
        }
    }

    public function arcadeLink(): never
    {
        try {
            $created = (new FederationDropService($this->app))->arcadeLink(
                $this->request->queryString('id'),
                $this->request->queryString('t')
            );
            $content = (string)$created['content'];
            if ($content === '' || strlen($content) > ArcadeLinkService::MAX_BYTES) {
                throw new FederationException('ArcadeLink FederationDrop inválido.', 500);
            }
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$created['filename']) ?: 'FederationDrop.arcadelink';
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($content));
            echo $content;
            exit;
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo emitir ArcadeLink para FederationDrop.'], 500);
        }
    }
}
