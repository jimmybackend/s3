<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

use DateTimeImmutable;

/**
 * Read-only, explicitly configured allocation. Never calls AWS or asserts
 * that a configured monthly budget is a line item in the AWS invoice.
 */
final class UserAwsAccounting
{
    public static function configuredRate(string $name): ?float
    {
        $raw = getenv($name);
        if (!is_string($raw) || !preg_match('/^(?:[0-9]{1,8})(?:\.[0-9]{1,8})?$/D', trim($raw))) {
            return null;
        }
        $value = (float)$raw;
        return is_finite($value) ? $value : null;
    }

    public static function build(
        array $breakdown,
        array $snapshot,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?float $s3UsdGbMonth,
        ?float $ec2UsdMonth,
        ?float $realAws,
        bool $currentSnapshot = true
    ): array {
        $users = [];
        foreach ((array)($breakdown['users'] ?? []) as $row) {
            $id = (int)($row['user_id'] ?? 0);
            if ($id <= 0) continue;
            $users[$id] = [
                'user_id' => $id,
                'direct' => max(0.0, (float)($row['estimated_cost'] ?? 0)),
                'incomplete' => (int)($row['partial_count'] ?? 0) + (int)($row['unpriced_count'] ?? 0),
                'bytes' => 0, 'files' => 0,
            ];
        }
        foreach ($snapshot as $row) {
            $id = (int)($row['user_id'] ?? 0);
            if ($id <= 0) continue;
            if (!isset($users[$id])) {
                $users[$id] = ['user_id' => $id, 'direct' => 0.0, 'incomplete' => 0, 'bytes' => 0, 'files' => 0];
            }
            $users[$id]['bytes'] = max(0, (int)($row['bytes'] ?? 0));
            $users[$id]['files'] = max(0, (int)($row['file_count'] ?? 0));
        }
        ksort($users, SORT_NUMERIC);

        // Calendar-day approximation. Today is counted as one full day,
        // never passed off as an exact hourly AWS charge.
        $monthFraction = 0.0;
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $monthFraction += 1.0 / (int)$day->format('t');
        }
        $canAllocateS3 = $currentSnapshot && $s3UsdGbMonth !== null;
        $canAllocateEc2 = $ec2UsdMonth !== null;
        $totalBytes = array_sum(array_column($users, 'bytes'));
        $totalEc2 = $canAllocateEc2 ? $ec2UsdMonth * $monthFraction : null;
        $members = count($users);
        $rows = [];
        $direct = 0.0;
        $s3 = 0.0;
        $ec2 = 0.0;
        foreach ($users as $row) {
            $s3Cost = $canAllocateS3 ? $row['bytes'] / 1073741824 * $s3UsdGbMonth * $monthFraction : null;
            $ec2Cost = $canAllocateEc2 && $members > 0 ? $totalEc2 / $members : null;
            $direct += $row['direct'];
            $s3 += $s3Cost ?? 0.0;
            $ec2 += $ec2Cost ?? 0.0;
            $rows[] = $row + [
                's3_estimate' => $s3Cost,
                'ec2_share' => $ec2Cost,
                'allocated_total' => $row['direct'] + ($s3Cost ?? 0.0) + ($ec2Cost ?? 0.0),
            ];
        }
        $allocated = $direct + $s3 + $ec2;
        return [
            'users' => $rows,
            'totals' => [
                'direct' => $direct,
                's3' => $canAllocateS3 ? $s3 : null,
                'ec2' => $canAllocateEc2 && $members > 0 ? $ec2 : null,
                'allocated' => $allocated,
                'real_aws' => $realAws,
                'unassigned' => $realAws !== null ? $realAws - $allocated : null,
                'fully_configured' => $canAllocateS3 && $canAllocateEc2,
            ],
            'policy' => [
                's3_usd_gb_month' => $s3UsdGbMonth,
                'ec2_usd_month' => $ec2UsdMonth,
                'month_fraction' => $monthFraction,
                'snapshot_available' => $currentSnapshot,
                'total_catalog_bytes' => $totalBytes,
                'allocation' => 'EC2 dividida en partes iguales entre usuarios con actividad o almacenamiento registrados',
            ],
        ];
    }
}
