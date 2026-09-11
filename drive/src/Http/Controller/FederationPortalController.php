<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationService;
use ArcadeCloud\Drive\View\FederationPortalRenderer;
use Throwable;

final class FederationPortalController
{
    public function __construct(
        private DriveApplication $app,
        private FederationPortalRenderer $renderer
    ) {
    }

    public function index(): void
    {
        $session = $this->app->session();
        $session->start();

        $authenticated = $session->isAuthenticated();
        $node = null;
        if ($authenticated) {
            try {
                $node = (new FederationService($this->app))->nodeDescriptor();
            } catch (Throwable) {
                $node = null;
            }
        }

        if (!$authenticated) {
            http_response_code(401);
        }

        $this->renderer->render($authenticated, $node);
    }
}
