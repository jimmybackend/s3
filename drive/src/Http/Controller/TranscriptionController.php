<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\TranscriptionFileService;
use ArcadeCloud\Drive\Http\JsonResponse;

final class TranscriptionController extends AbstractJsonController
{
    public function start(): never
    {
        $userId = 0;
        $started = microtime(true);

        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $result = $this->service()->start($userId, $this->request->allPost());
            $jobName = (string)($result['jobName'] ?? '');

            // El inicio se conserva como evento no tasado. Al completar, el
            // mismo CorrelationId se actualiza con los segundos atribuibles y
            // su costo, evitando duplicar una misma transcripción.
            $this->activity()->success(
                $userId,
                'transcribe',
                'Transcribe',
                $this->fileId($userId, (string)($result['archivoEncriptado'] ?? $result['archivo'] ?? '')),
                ['transcribe.job_started' => 1],
                $started,
                [
                    'phase' => 'started',
                    'status' => (string)($result['status'] ?? 'UNKNOWN'),
                ],
                ActivityCostRecorder::correlation('transcribe', $jobName)
            );

            JsonResponse::send($result);
        } catch (\Throwable $e) {
            if ($userId > 0) {
                $this->activity()->failure(
                    $userId,
                    'transcribe',
                    'Transcribe',
                    $started,
                    ['phase' => 'start']
                );
            }
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function status(): never
    {
        $userId = 0;
        $started = microtime(true);
        $jobName = '';

        try {
            $userId = $this->guardAuthenticated();
            $jobName = $this->first('jobName');
            $file = $this->first('archivo');
            $result = $this->service()->status($userId, $jobName, $file);

            if (in_array((string)($result['status'] ?? ''), ['COMPLETED', 'FAILED'], true)) {
                $status = (string)$result['status'];
                $correlation = ActivityCostRecorder::correlation('transcribe', $jobName);

                if ($status === 'COMPLETED') {
                    $attribution = is_array($result['cost_attribution'] ?? null)
                        ? $result['cost_attribution']
                        : [];
                    $units = is_array($attribution['units'] ?? null)
                        ? $attribution['units']
                        : ['transcribe.job_completed' => 1];
                    $features = is_array($attribution['features'] ?? null)
                        ? $attribution['features']
                        : [];

                    $this->activity()->success(
                        $userId,
                        'transcribe',
                        'Transcribe',
                        $this->fileId($userId, (string)($result['archivoKey'] ?? $file)),
                        $units,
                        $started,
                        [
                            'phase' => 'completed',
                            'status' => $status,
                            'duration_seconds_observed' => (float)($attribution['duration_seconds_observed'] ?? 0),
                            'billable_seconds_reference' => (int)($attribution['billable_seconds_reference'] ?? 0),
                            'duration_source' => (string)($attribution['duration_source'] ?? 'unavailable'),
                            'pricing_region_reference' => (string)($attribution['pricing_region_reference'] ?? 'us-east-1'),
                            'content_redaction' => (bool)($features['content_redaction'] ?? false),
                            'custom_language_model' => (bool)($features['custom_language_model'] ?? false),
                            'toxicity_detection' => (bool)($features['toxicity_detection'] ?? false),
                        ],
                        $correlation
                    );

                    $this->recordGeneratedS3Cost($userId, $result, $started, $correlation);
                } else {
                    $this->activity()->failure(
                        $userId,
                        'transcribe',
                        'Transcribe',
                        $started,
                        ['phase' => 'failed', 'status' => $status],
                        $correlation
                    );
                }
            }

            JsonResponse::send($result);
        } catch (\Throwable $e) {
            if ($userId > 0 && $jobName !== '') {
                $this->activity()->failure(
                    $userId,
                    'transcribe',
                    'Transcribe',
                    $started,
                    ['phase' => 'status'],
                    ActivityCostRecorder::correlation('transcribe', $jobName)
                );
            }
            JsonResponse::send(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function recordGeneratedS3Cost(
        int $userId,
        array $result,
        float $started,
        ?string $correlation
    ): void {
        $saved = is_array($result['guardados'] ?? null) ? $result['guardados'] : [];
        if ($saved === []) {
            return;
        }

        $putRequests = 0;
        $storageBytes = 0;
        foreach ($saved as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $putRequests++;
            $storageBytes += max(0, (int)($variant['tamano'] ?? 0));
        }

        if ($putRequests <= 0) {
            return;
        }

        $this->activity()->success(
            $userId,
            'transcribe',
            'S3',
            $this->fileId($userId, (string)($result['archivoKey'] ?? '')),
            [
                's3.put_request' => $putRequests,
                's3.storage_bytes_delta' => $storageBytes,
            ],
            $started,
            [
                'phase' => 'generated_outputs',
                'generated_files' => $putRequests,
                'storage_bytes_delta' => $storageBytes,
            ],
            $correlation
        );
    }

    private function service(): TranscriptionFileService
    {
        return new TranscriptionFileService(
            new FileRecordLocator($this->app->db()),
            new GeneratedFileRepository($this->app->db()),
            $this->app->s3(),
            \Config::getTranscribe(),
            $this->app->bucket()
        );
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }

    private function fileId(int $userId, string $key): ?int
    {
        if ($key === '') {
            return null;
        }

        try {
            $row = (new FileRecordLocator($this->app->db()))->requireReadableByKey($userId, $key);
            $id = (int)($row['id_'] ?? 0);
            return $id > 0 ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function first(string $name): string
    {
        $value = $this->request->postString($name);
        return $value !== '' ? $value : $this->request->queryString($name);
    }
}
