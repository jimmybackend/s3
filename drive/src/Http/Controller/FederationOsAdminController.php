<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\FederationOsAdminRenderer;

final class FederationOsAdminController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request,
        private FederationOsAdminRenderer $renderer
    ) {
    }

    public function index(): void
    {
        $session = $this->app->session();
        $session->start();
        $session->requireAuthenticated('../index.php');

        $isSuperAdmin = $session->isSuperAdmin();
        $providerCsrf = '';
        $serverAdminCsrf = '';

        if ($isSuperAdmin) {
            $providerCsrf = $this->ensureToken($session, 'federation_provider_csrf');
            $serverAdminCsrf = $this->ensureToken($session, 'server_admin_csrf');
        }

        $this->renderer->render(
            $isSuperAdmin,
            $providerCsrf,
            $serverAdminCsrf,
            $this->request->queryString('embed') === '1'
        );
    }

    private function ensureToken(object $session, string $key): string
    {
        $token = (string)$session->get($key, '');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $token = bin2hex(random_bytes(32));
            $session->set($key, $token);
        }
        return $token;
    }
}
