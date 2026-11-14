<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Application\FolderDetailCatalogService;
use ArcadeCloud\Drive\Http\JsonResponse;

final class FolderDetailCatalogController extends AbstractJsonController
{
    public function generate(): never
    {
        $this->requirePost();
        $userId = $this->guardAuthenticated();
        $this->requireDriveCsrf();

        try {
            $service = new FolderDetailCatalogService(
                $this->app->db(),
                $this->app->userStoragePath(),
                $this->app->singleUploadService(),
                $this->app->s3(),
                $this->app->bucket()
            );
            JsonResponse::send([
                'ok' => true,
                'data' => $service->generate($userId, $this->request->postString('route')),
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 400);
        }
    }
}
