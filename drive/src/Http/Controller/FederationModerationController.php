<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationException;
use ArcadeCloud\Drive\Federation\FederationModerationService;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\FederationModerationPageRenderer;
use ArcadeCloud\Drive\View\FederationReportPageRenderer;
use Throwable;

final class FederationModerationController
{
    public function __construct(private DriveApplication $app, private Request $request)
    {
    }

    public function reportPage(): void
    {
        (new FederationReportPageRenderer())->render(
            $this->request->queryString('type'),
            $this->request->queryString('id')
        );
    }

    public function reportApi(): never
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        try {
            $honeypot = trim($this->request->postString('website'));
            if ($honeypot !== '') {
                JsonResponse::send(['ok' => true, 'status' => 'pending'], 202);
            }
            $result = (new FederationModerationService($this->app))->submitReport(
                $this->request->postString('target_type'),
                $this->request->postString('target_id'),
                $this->request->postString('category'),
                $this->request->postString('details'),
                $this->request->postString('reporter_email')
            );
            JsonResponse::send($result, 201);
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable $e) {
            error_log('[Federation moderation report] ' . $e->getMessage());
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo registrar el reporte.'], 500);
        }
    }

    public function adminPage(): void
    {
        $session = $this->app->session();
        $session->start();
        $session->requireAuthenticated('../index.php');
        if (!$session->isSuperAdmin()) {
            http_response_code(403);
            echo 'Sólo el superusuario puede revisar moderación FederationCloud.';
            return;
        }
        $token = bin2hex(random_bytes(24));
        $session->set('federation_moderation_csrf', $token);
        (new FederationModerationPageRenderer())->render($token);
    }

    public function adminApi(): never
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated()) {
            JsonResponse::send(['ok' => false, 'error' => 'Autenticación requerida.'], 401);
        }
        if (!$session->isSuperAdmin()) {
            JsonResponse::send(['ok' => false, 'error' => 'Sólo el superusuario puede decidir reportes.'], 403);
        }

        try {
            $service = new FederationModerationService($this->app);
            if ($this->request->method() === 'GET') {
                JsonResponse::send($service->adminState());
            }
            if ($this->request->method() !== 'POST') {
                JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
            }
            $expected = (string)$session->get('federation_moderation_csrf', '');
            $sent = $this->request->serverString('HTTP_X_FEDERATION_MODERATION_CSRF');
            if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
                JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página.'], 403);
            }
            $action = strtolower(trim($this->request->postString('action')));
            if ($action === 'unblock') {
                JsonResponse::send($service->unblock(
                    $this->request->postString('content_id'),
                    $session->userId(),
                    $this->request->postString('reason')
                ));
            }

            JsonResponse::send($service->decide(
                $this->request->postString('report_id'),
                $this->request->postString('decision'),
                $session->userId(),
                $this->request->postString('reason')
            ));
        } catch (FederationException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable $e) {
            error_log('[Federation moderation admin] ' . $e->getMessage());
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo completar la decisión de moderación.'], 500);
        }
    }
}
