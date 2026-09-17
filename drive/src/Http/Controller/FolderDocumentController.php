<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Application\FolderDocumentService;
use ArcadeCloud\Drive\Http\JsonResponse;

final class FolderDocumentController extends AbstractJsonController
{
    public function create(): never
    {
        $this->requirePost();
        $userId = $this->guardAuthenticated();
        $started = microtime(true);

        try {
            $service = new FolderDocumentService(
                $this->app->db(),
                $this->app->singleUploadService(),
                $this->app->userStoragePath()
            );

            $result = $service->create(
                $userId,
                trim($this->request->postString('route')),
                trim($this->request->postString('name')),
                trim($this->request->postString('format')),
                $this->request->postString('plain_text'),
                $this->request->postString('html_content'),
                $this->request->serverString('REMOTE_ADDR', 'unknown'),
                $this->request->serverString('HTTP_USER_AGENT', 'unknown')
            );

            $bytes = max(0, (int)($result['tamano'] ?? 0));
            ActivityCostRecorder::fromDatabase($this->app->db())->success(
                $userId,
                'create_document',
                'S3',
                (int)($result['id'] ?? 0),
                [
                    's3.put_request' => 1,
                    's3.storage_bytes_delta' => $bytes,
                ],
                $started,
                [
                    'mode' => 'folder_paste',
                    'format' => (string)($result['format'] ?? ''),
                    'size_bytes' => $bytes,
                ]
            );

            JsonResponse::send([
                'ok' => true,
                'message' => 'Documento guardado en la carpeta.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            ActivityCostRecorder::fromDatabase($this->app->db())->failure(
                $userId,
                'create_document',
                'S3',
                $started,
                ['mode' => 'folder_paste']
            );
            $this->fail($e, 400);
        }
    }
}
