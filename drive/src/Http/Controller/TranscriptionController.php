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
            $this->activity()->success(
                $userId,
                'transcribe',
                'Transcribe',
                $this->fileId($userId, (string)($result['archivoEncriptado'] ?? $result['archivo'] ?? '')),
                ['transcribe.job_started' => 1],
                $started,
                ['phase' => 'started', 'status' => (string)($result['status'] ?? 'UNKNOWN')],
                ActivityCostRecorder::correlation('transcribe', $jobName)
            );
            JsonResponse::send($result);
        } catch (\Throwable $e) {
            if ($userId > 0) {
                $this->activity()->failure($userId, 'transcribe', 'Transcribe', $started, ['phase' => 'start']);
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
                    $this->activity()->success(
                        $userId,
                        'transcribe',
                        'Transcribe',
                        $this->fileId($userId, (string)($result['archivoKey'] ?? $file)),
                        ['transcribe.job_completed' => 1],
                        $started,
                        ['phase' => 'completed', 'status' => $status],
                        $correlation
                    );
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
        if ($key === '') return null;
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
