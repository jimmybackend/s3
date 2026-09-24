<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use ArcadeCloud\Drive\Media\MediaProcessingService;

final class MediaProcessingController extends AbstractJsonController
{
    public function dispatch(): never
    {
        try {
            $userId = $this->guardAuthenticated();
            $jobs = new MediaProcessingJobRepository($this->app->db());
            $service = new MediaProcessingService($this->app->fileRecordLocator(), $jobs);

            if ($this->request->method() === 'POST') {
                $result = $service->enqueue($userId, $this->request->allPost());
                JsonResponse::send($result, 202);
            }

            JsonResponse::send($service->recent($userId));
        } catch (\Throwable $e) {
            $this->fail($e, 400);
        }
    }
}
