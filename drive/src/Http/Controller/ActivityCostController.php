<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRepository;
use ArcadeCloud\Drive\Activity\ActivityCostService;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\ActivityCostPageRenderer;

final class ActivityCostController
{
    public function __construct(
        private DriveApplication $app,
        private Request $request
    ) {
    }

    public function page(): never
    {
        $session = $this->app->session();
        $session->start();
        $session->requireAuthenticated('index.php');
        $userId = $session->userId();
        if ($userId <= 0) {
            header('Location: index.php');
            exit;
        }

        $canViewRealAws = $this->app->personalToolAccessService()->state() === 'owner';
        $session->closeWrite();

        $service = new ActivityCostService(
            new ActivityCostRepository($this->app->db()),
            $this->app->costExplorerGateway()
        );

        try {
            $view = $service->dashboard(
                $userId,
                $this->request->queryString('period', 'month'),
                $this->request->queryString('service'),
                $this->request->queryString('action'),
                $canViewRealAws
            );
            echo (new ActivityCostPageRenderer())->render($view, $userId);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo (new ActivityCostPageRenderer())->renderError($e->getMessage());
        }
        exit;
    }
}
