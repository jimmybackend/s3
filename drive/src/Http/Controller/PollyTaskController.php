<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\PollyFileService;
use ArcadeCloud\Drive\Http\JsonResponse;

final class PollyTaskController extends AbstractJsonController
{
    public function status(): never
    {
        $this->requirePost();
        $userId = 0;
        $started = microtime(true);

        try {
            $userId = $this->guardAuthenticated();
            $result = $this->service()->taskStatus($userId, $this->request->allPost());

            if (($result['task_status'] ?? '') === 'completed') {
                $this->repairCatalogIfNeeded($userId, $result);
                $this->recordFinalizationCost($userId, $result, $started);
            }

            JsonResponse::send($result);
        } catch (\Throwable $e) {
            if ($userId > 0) {
                try {
                    ActivityCostRecorder::fromDatabase($this->app->db())->failure(
                        $userId,
                        'polly_task_status',
                        'Polly',
                        $started
                    );
                } catch (\Throwable) {
                }
            }

            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function service(): PollyFileService
    {
        return new PollyFileService(
            new FileRecordLocator($this->app->db()),
            new GeneratedFileRepository($this->app->db()),
            $this->app->s3(),
            \Config::getPolly(),
            $this->app->bucket()
        );
    }

    private function repairCatalogIfNeeded(int $userId, array &$result): void
    {
        if (($result['db_status'] ?? '') !== 'ya_finalizado') {
            return;
        }

        $key = trim((string)($result['s3_key'] ?? ''));
        $name = trim((string)($result['nombre'] ?? ''));
        $route = (string)($result['ruta'] ?? '');
        $size = max(0, (int)($result['output_bytes'] ?? 0));

        if ($key === '' || $name === '') {
            return;
        }

        $meta = [
            'tipo' => (string)($result['contentType'] ?? 'audio/mpeg'),
            'servicio' => 'Amazon Polly',
            'engine' => (string)($result['engine_used'] ?? 'standard'),
            'polly_task_id' => (string)($result['task_id'] ?? ''),
            'polly_task_status' => 'completed',
            'destino' => $key,
            'ruta' => $route,
            'tamano_bytes' => $size,
            'fecha' => date('Y-m-d'),
            'hora' => date('H:i:s'),
        ];

        $result['db_status'] = (new GeneratedFileRepository($this->app->db()))->upsert(
            $userId,
            $name,
            $key,
            $size,
            $meta,
            $route
        );
    }

    private function recordFinalizationCost(int $userId, array $result, float $started): void
    {
        if (($result['finalized_now'] ?? false) !== true) {
            return;
        }

        $units = [
            's3.copy_request' => max(0, (int)($result['s3_copy_requests'] ?? 0)),
            's3.delete_request' => max(0, (int)($result['s3_delete_requests'] ?? 0)),
            's3.head_request' => max(0, (int)($result['s3_head_requests'] ?? 0)),
            's3.storage_bytes_delta' => max(0, (int)($result['output_bytes'] ?? 0)),
        ];

        ActivityCostRecorder::fromDatabase($this->app->db())->success(
            $userId,
            'polly_finalize',
            'S3',
            ((int)($result['source_file_id'] ?? 0)) > 0 ? (int)$result['source_file_id'] : null,
            $units,
            $started,
            [
                'task_id' => (string)($result['task_id'] ?? ''),
                'engine' => (string)($result['engine_used'] ?? ''),
            ],
            ActivityCostRecorder::correlation('polly_finalize', (string)($result['task_id'] ?? ''))
        );
    }
}
