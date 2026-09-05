<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use Throwable;

final class FolderQueryController extends AbstractJsonController
{
    public function list(): never
    {
        try {
            $userId = $this->guardAuthenticated();
            JsonResponse::send([
                'ok' => true,
                'carpetas' => $this->app->folderQueryService()->allForUser($userId, true),
            ]);
        } catch (Throwable $e) {
            JsonResponse::send([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
