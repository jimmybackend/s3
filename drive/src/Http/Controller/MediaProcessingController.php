<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use ArcadeCloud\Drive\Media\MediaProcessingService;
use ArcadeCloud\Drive\Media\MediaWorkerNodeService;

final class MediaProcessingController extends AbstractJsonController
{
    public function dispatch(): never
    {
        try {
            $userId = $this->guardAuthenticated();
            $jobs = new MediaProcessingJobRepository($this->app->db());
            $node = new MediaWorkerNodeService($this->app->db());
            $service = new MediaProcessingService($this->app->fileRecordLocator(), $jobs, $node);

            if ($this->request->method() === 'POST') {
                $result = $service->enqueue($userId, $this->request->allPost());
                JsonResponse::send($result, 202);
            }

            if ($this->request->queryString('node_status') === '1') {
                JsonResponse::send(['ok' => true, 'node' => $node->status()]);
            }

            $recent = $service->recent($userId);
            $recent['node'] = $node->status();
            JsonResponse::send($recent);
        } catch (\Throwable $e) {
            $this->fail($e, 400);
        }
    }
}
