<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use ArcadeCloud\Drive\Admin\ServerConsoleService;
use ArcadeCloud\Drive\Aws\PersonalAwsRuntime;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ServerConsoleController
{
    public function __construct(
        private PersonalAwsRuntime $runtime,
        private Request $request
    ) {
    }

    public function api(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        $session = $this->runtime->session();
        $session->start();

        if (!$session->isAuthenticated()) {
            JsonResponse::send(['ok' => false, 'error' => 'Autenticación del Drive requerida.'], 401);
        }
        if (!$session->isSuperAdmin()) {
            JsonResponse::send(['ok' => false, 'error' => 'Sólo el superusuario puede abrir la terminal del servidor.'], 403);
        }

        $service = new ServerConsoleService(
            new PrivilegedServerHelper(),
            $this->runtime->config()
        );

        try {
            if ($this->request->method() === 'GET') {
                JsonResponse::send($service->state());
            }
            if ($this->request->method() !== 'POST') {
                JsonResponse::send(['ok' => false, 'error' => 'Método no permitido.'], 405);
            }

            $expected = (string)$session->get('csrf', '');
            $sent = $this->request->postString('csrf');
            if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
                JsonResponse::send(['ok' => false, 'error' => 'Token CSRF inválido. Recarga EC2.'], 403);
            }

            JsonResponse::send($service->execute(
                $this->request->postRawString('command'),
                $this->request->postRawString('access_password')
            ));
        } catch (InvalidArgumentException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            error_log('[ArcadeCloud server-console] ' . $e->getMessage());
            JsonResponse::send(['ok' => false, 'error' => 'No se pudo ejecutar el diagnóstico del servidor.'], 500);
        }
    }
}
