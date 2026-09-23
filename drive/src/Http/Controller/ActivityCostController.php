<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
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

        $period = $this->request->queryString('period', 'month');
        $serviceFilter = $this->request->queryString('service');
        $actionFilter = $this->request->queryString('action');
        $page = max(1, (int)$this->request->queryString('page', '1'));

        try {
            $view = $service->dashboard($userId, $period, $serviceFilter, $actionFilter, $canViewRealAws, $page);

            if (($view['real_aws']['available'] ?? false) === true
                && ($view['real_aws']['cached'] ?? true) === false) {
                ActivityCostRecorder::fromDatabase($this->app->db())->success(
                    $userId,
                    'cost_explorer',
                    'CostExplorer',
                    null,
                    ['cost_explorer.api_request' => 1],
                    microtime(true),
                    ['api_requests' => 1]
                );

                $view = $service->dashboard($userId, $period, $serviceFilter, $actionFilter, $canViewRealAws, $page);
            }

            $html = (new ActivityCostPageRenderer())->render($view, $userId);
            echo $this->withBackgroundTasks($html);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo (new ActivityCostPageRenderer())->renderError($e->getMessage());
        }
        exit;
    }

    private function withBackgroundTasks(string $html): string
    {
        $script = '<script>window.ARCADECLOUD_UNIFIED_TASK_CENTER=true;</script>'
            . '<script src="js/background-tasks.js?v=20260916-2"></script>';
        if (str_contains($html, '</body>')) {
            return str_replace('</body>', $script . "\n</body>", $html);
        }
        return $html . $script;
    }
}
