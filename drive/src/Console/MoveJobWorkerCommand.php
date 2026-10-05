<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Console;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Core\BackgroundWorkerLease;
use Throwable;

final class MoveJobWorkerCommand
{
    public function run(array $argv): int
    {
        $jobId = strtolower(trim((string)($argv[1] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            return 3;
        }

        $lease = new BackgroundWorkerLease();
        $leaseHandle = $lease->acquire('move', $jobId);
        if ($leaseHandle === null) {
            return 0;
        }

        try {
            $app = ApplicationKernel::app();
            $before = $app->moveJobStore()->get($jobId);
            if ((string)($before['status'] ?? '') !== 'queued') {
                return 0;
            }

            $started = microtime(true);
            $final = $app->moveJobService()->run($jobId);
            if (($final['_worker_claimed'] ?? false) !== true) {
                return 0;
            }

            $userId = (int)($final['user_id'] ?? 0);
            $type = (string)($final['type'] ?? '');
            $status = (string)($final['status'] ?? '');
            $payload = is_array($final['payload'] ?? null) ? $final['payload'] : [];
            $result = is_array($final['result'] ?? null) ? $final['result'] : [];
            $operation = strtolower((string)($payload['operation'] ?? 'move'));
            if (!in_array($operation, ['move', 'copy'], true)) $operation = 'move';
            $copying = $operation === 'copy';

            $correlation = ActivityCostRecorder::correlation('move-job', $jobId);
            $activity = ActivityCostRecorder::fromDatabase($app->db());

            if ($userId <= 0 || $correlation === null) {
                return 0;
            }

            if ($type === 'files') {
                $total = max(0, (int)($result['total'] ?? 0));
                if ($total > 0) {
                    $derivedLists = max(0, (int)($result['derived_list_requests'] ?? 0));
                    $derivedCopies = max(0, (int)($result['derived_copy_requests'] ?? 0));
                    $derivedDeletes = max(0, (int)($result['derived_delete_requests'] ?? 0));
                    $units = [
                        's3.list_request' => $derivedLists,
                        's3.copy_request' => $total + $derivedCopies,
                    ];
                    if (!$copying) {
                        $units['s3.delete_request'] = $total + $derivedDeletes;
                    }
                    if (($payload['created_destination'] ?? false) === true) {
                        $units['s3.put_request'] = 1;
                    }

                    $activity->success(
                        $userId,
                        $copying ? 'copy' : 'move',
                        'S3',
                        null,
                        $units,
                        $started,
                        [
                            'items' => $total,
                            'requested_items' => max($total, (int)($result['requested_total'] ?? $total)),
                            'operation' => $operation,
                            'async' => true,
                            'job_status' => $status,
                            'created_destination' => (bool)($payload['created_destination'] ?? false),
                            'derived_list_requests' => $derivedLists,
                            'derived_copy_requests' => $derivedCopies,
                            'derived_delete_requests' => $derivedDeletes,
                            'worker' => 'move_job_worker',
                        ],
                        $correlation
                    );
                } elseif ($status === 'failed') {
                    $activity->failure(
                        $userId,
                        $copying ? 'copy' : 'move',
                        'S3',
                        $started,
                        ['operation' => $operation, 'async' => true, 'worker' => 'move_job_worker'],
                        $correlation
                    );
                }
            } elseif ($type === 'folder') {
                $units = [
                    's3.list_request' => max(0, (int)($result['s3_list_requests'] ?? 0)),
                    's3.copy_request' => max(0, (int)($result['s3_copy_requests'] ?? 0)),
                    's3.delete_request' => max(0, (int)($result['s3_delete_requests'] ?? 0)),
                    's3.put_request' => max(0, (int)($result['s3_put_requests'] ?? 0)),
                ];
                $hasUnits = array_sum($units) > 0;

                if ($hasUnits || $status === 'completed') {
                    $activity->success(
                        $userId,
                        $copying ? 'folder_copy' : 'folder_move',
                        'S3',
                        null,
                        $units,
                        $started,
                        [
                            'items' => max(0, (int)($result['s3_copy_requests'] ?? 0)),
                            'operation' => $operation,
                            'async' => true,
                            'job_status' => $status,
                            'worker' => 'move_job_worker',
                        ],
                        $correlation
                    );
                } elseif ($status === 'failed') {
                    $activity->failure(
                        $userId,
                        $copying ? 'folder_copy' : 'folder_move',
                        'S3',
                        $started,
                        ['operation' => $operation, 'async' => true, 'worker' => 'move_job_worker'],
                        $correlation
                    );
                }
            }

            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, 'Move job worker error: ' . $error->getMessage() . PHP_EOL);
            return 1;
        } finally {
            if (is_resource($leaseHandle)) {
                @flock($leaseHandle, LOCK_UN);
                @fclose($leaseHandle);
            }
        }
    }
}
