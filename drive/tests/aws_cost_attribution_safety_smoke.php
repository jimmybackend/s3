<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Activity/AwsUnitPriceCatalog.php';

use ArcadeCloud\Drive\Activity\AwsUnitPriceCatalog;

function assertCost(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$catalog = AwsUnitPriceCatalog::withoutMediaWorkerRate();
$missing = $catalog->estimate(['ec2.media_worker_second' => 3600]);
assertCost($missing['amount'] === null && $missing['state'] === 'unpriced',
    'Missing historical EC2 rate must not become zero or current tariff');
$priced = $catalog->withMediaSessionHourlyUsd(0.12345678)
    ->estimate(['ec2.media_worker_second' => 1800]);
assertCost($priced['state'] === 'complete'
    && abs((float)$priced['amount'] - 0.06172839) < 0.0000000001,
    'Captured EC2 session rate is applied only to observed seconds');
$intent = $catalog->estimate(['s3.presigned_get_intent' => 1]);
assertCost($intent['amount'] === null && $intent['state'] === 'unpriced',
    'Presigned URL intent is never charged as actual S3 GET');
$pending = $catalog->estimate(['transcribe.job_started' => 1]);
assertCost($pending['amount'] === null && $pending['state'] === 'unpriced',
    'Transcribe started is not a completed priced job');
echo "OK EC2 session snapshot, S3 GET intent and Transcribe pending protections.\n";
