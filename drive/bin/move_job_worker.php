<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$jobId = strtolower(trim((string)($argv[1] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
    exit(3);
}

require dirname(__DIR__) . '/app_bootstrap.php';

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Core\ApplicationKernel;

try {
    $app = ApplicationKernel::app();
    $before = $app->moveJobStore()->get($jobId);
    if ((string)($before['status'] ?? '') !== 'queued') {
        exit(0);
    }

    $started = microtime(true);
    $final = $app->moveJobService()->run($jobId);
    if (($final['_worker_claimed'] ?? false) !== true) {
        exit(0);
    }

    $userId = (int)($final['user_id'] ?? 0);
    $type = (string)($final['type'] ?? '');
    $status = (string)($final['status'] ?? '');
    $payload = is_array($final['payload'] ?? null) ? $final['payload'] : [];
    $result = is_array($final['result'] ?? null) ? $final['result'] : [];
    $correlation = ActivityCostRecorder::correlation('move-job', $jobId);
    $activity = ActivityCostRecorder::fromDatabase($app->db());

    if ($userId <= 0 || $correlation === null) {
        exit(0);
    }

    if ($type === 'files') {
        $total = max(0, (int)($result['total'] ?? 0));
        if ($total > 0) {
            $units = [
                's3.copy_request' => $total,
                's3.delete_request' => $total,
            ];
            if (($payload['created_destination'] ?? false) === true) {
                $units['s3.put_request'] = 1;
            }

            $activity->success(
                $userId,
                'move',
                'S3',
                null,
                $units,
                $started,
                [
                    'items' => $total,
                    'requested_items' => max($total, (int)($result['requested_total'] ?? $total)),
                    'async' => true,
                    'job_status' => $status,
                    'created_destination' => (bool)($payload['created_destination'] ?? false),
                    'worker' => 'move_job_worker',
                ],
                $correlation
            );
        } elseif ($status === 'failed') {
            $activity->failure(
                $userId,
                'move',
                'S3',
                $started,
                ['async' => true, 'worker' => 'move_job_worker'],
                $correlation
            );
        }
    } elseif ($type === 'folder') {
        $units = [
            's3.list_request' => max(0, (int)($result['s3_list_requests'] ?? 0)),
            's3.copy_request' => max(0, (int)($result['s3_copy_requests'] ?? 0)),
            's3.delete_request' => max(0, (int)($result['s3_delete_requests'] ?? 0)),
        ];
        $hasUnits = array_sum($units) > 0;

        if ($hasUnits || $status === 'completed') {
            $activity->success(
                $userId,
                'folder_move',
                'S3',
                null,
                $units,
                $started,
                [
                    'items' => max(0, (int)($result['s3_copy_requests'] ?? 0)),
                    'async' => true,
                    'job_status' => $status,
                    'worker' => 'move_job_worker',
                ],
                $correlation
            );
        } elseif ($status === 'failed') {
            $activity->failure(
                $userId,
                'folder_move',
                'S3',
                $started,
                ['async' => true, 'worker' => 'move_job_worker'],
                $correlation
            );
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Move job worker error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
