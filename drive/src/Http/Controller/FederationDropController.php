<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\ArcadeLinkService;
use ArcadeCloud\Drive\Federation\FederationDropGoogleAuthConfig;
use ArcadeCloud\Drive\Federation\FederationDropGoogleAuthService;
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
        $state = $service->publicState();
        $resourceId = trim($this->request->queryString('resource_id'));
        if ($resourceId !== '') {
            $state['source_resource'] = $service->publicResourceState($resourceId);
        }
        $state['google_auth'] = (new FederationDropGoogleAuthService($this->app))->pageState(
            $this->request->cookieString(FederationDropGoogleAuthService::SESSION_COOKIE),
            $this->request->queryString('source'),
            $resourceId
        );
        (new FederationDropPageRenderer())->render(
            $state,
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
                $owner = $this->googleOwnerIdentity();
                JsonResponse::send($service->createOrder(
                    $owner !== null ? (string)$owner['email'] : $this->request->postString('email'),
                    $this->request->postString('filename'),
                    $this->request->postInt('size_bytes'),
                    $this->request->postString('mime_type', 'application/octet-stream'),
                    $this->request->postInt('days'),
                    $this->request->postInt('downloads'),
                    $this->request->postString('source_domain'),
                    $owner !== null ? (string)$owner['account_id'] : null
                ), 201);
            }

            if ($action === 'create-public-resource' && $this->request->method() === 'POST') {
                $owner = $this->googleOwnerIdentity();
                JsonResponse::send($service->createPublicResourceOrder(
                    $owner !== null ? (string)$owner['email'] : $this->request->postString('email'),
                    $this->request->postString('resource_id'),
                    $this->request->postInt('days'),
                    $this->request->postInt('downloads'),
                    $this->request->postString('source_domain'),
                    $owner !== null ? (string)$owner['account_id'] : null
                ), 201);
            }

            if ($action === 'ingress-candidates' && $this->request->method() === 'POST') {
                JsonResponse::send($service->ingressCandidates(
                    $this->request->postString('drop_id'),
                    $this->request->postString('owner_token')
                ));
            }

            if ($action === 'ingress-authorize' && $this->request->method() === 'POST') {
                JsonResponse::send($service->authorizeIngress(
                    $this->request->postString('drop_id'),
                    $this->request->postString('owner_token'),
                    $this->request->postString('ingress_node_id')
                ));
            }

            if ($action === 'ingress-register' && $this->request->method() === 'POST') {
                JsonResponse::send($service->registerIngressUploaded(
                    $this->request->postString('drop_id'),
                    $this->request->postString('owner_token'),
                    $this->request->postString('ingress_id')
                ));
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

            if (in_array($action, ['stripe-webhook', 'payment-webhook'], true) && $this->request->method() === 'POST') {
                $length = (int)$this->request->serverString('CONTENT_LENGTH', '0');
                if ($length <= 0 || $length > 1048576) {
                    JsonResponse::send(['ok' => false, 'error' => 'Webhook Stripe ausente o demasiado grande.'], 413);
                }
                $raw = file_get_contents('php://input', false, null, 0, 1048577);
                if (!is_string($raw) || $raw === '' || strlen($raw) > 1048576) {
                    JsonResponse::send(['ok' => false, 'error' => 'Webhook Stripe FederationDrop inválido.'], 413);
                }
                JsonResponse::send($service->receivePaymentWebhook(
                    $raw,
                    $this->request->serverString('HTTP_STRIPE_SIGNATURE')
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

    private function googleOwnerIdentity(): ?array
    {
        $cookie = $this->request->cookieString(FederationDropGoogleAuthService::SESSION_COOKIE);
        if ($cookie === '') return null;

        $config = FederationDropGoogleAuthConfig::fromEnvironment();
        if (!$config->ready()) return null;

        return (new FederationDropGoogleAuthService($this->app))->sessionIdentity($cookie);
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
