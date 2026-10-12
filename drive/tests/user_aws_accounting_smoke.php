<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Activity/UserAwsAccounting.php';

use ArcadeCloud\Drive\Activity\UserAwsAccounting;

function ensure(bool $condition, string $why): void {
    if (!$condition) throw new RuntimeException($why);
}
$start = new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('UTC'));
$end = new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC'));
$groups = ['users' => [
    ['user_id' => 1, 'estimated_cost' => 15.98, 'partial_count' => 2, 'unpriced_count' => 1],
    ['user_id' => 3, 'estimated_cost' => 0.02],
]];
$storage = [
    ['user_id' => 1, 'bytes' => 1073741824, 'file_count' => 10],
    ['user_id' => 3, 'bytes' => 1073741824, 'file_count' => 20],
    ['user_id' => 4, 'bytes' => 0, 'file_count' => 1],
];
$calc = UserAwsAccounting::build($groups, $storage, $start, $end, 0.023, 12.0, 19.36);
ensure(count($calc['users']) === 3, 'Must include storage-only user');
ensure(abs($calc['totals']['direct'] - 16.0) < 0.00001, 'All-user direct cost');
ensure(abs($calc['totals']['s3'] - 0.046) < 0.00001, 'S3 bytes share');
ensure(abs($calc['totals']['ec2'] - 12.0) < 0.00001, 'EC2 equal shares');
ensure(abs($calc['users'][0]['ec2_share'] - 4.0) < 0.00001, 'Per user EC2');
ensure(abs($calc['totals']['unassigned'] - (19.36 - 28.046)) < 0.00001, 'Signed difference');
$noRate = UserAwsAccounting::build($groups, $storage, $start, $end, null, null, 19.36);
ensure($noRate['totals']['s3'] === null && $noRate['totals']['ec2'] === null, 'Unknown rates not zero');
ensure($noRate['totals']['fully_configured'] === false, 'Incomplete attribution');
$historical = UserAwsAccounting::build($groups, $storage, $start, $end, 0.023, 12.0, null, false);
ensure($historical['totals']['s3'] === null, 'Historical snapshot must not be charged');
echo "OK: accounting by user, shared allocations, missing rates and historical storage.\n";
