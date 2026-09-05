<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use Throwable;

final class StorageUsageController extends AbstractJsonController
{
    public function show(): never
    {
        try {
            $userId = $this->guardAuthenticated();
            $force = $this->request->queryString('refresh') === '1';
            $usage = $this->app->storageUsageService()->getUsage($userId, $force);

            JsonResponse::ok([
                'bytes' => $usage['bytes'],
                'formatted' => $usage['formatted'],
                'cached_at' => $usage['cached_at'],
            ]);
        } catch (Throwable $e) {
            JsonResponse::error($e->getMessage(), 500);
        }
    }
}
