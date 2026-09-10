<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Activity\AwsUnitPriceCatalog;
use ArcadeCloud\Drive\View\ActivityCostPageRenderer;

spl_autoload_register(static function (string $class): void {
    $prefix = 'ArcadeCloud\\Drive\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require_once $path;
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$catalog = AwsUnitPriceCatalog::default();
$estimate = $catalog->estimate([
    's3.put_request' => 1,
    's3.get_request' => 2,
]);
check($estimate['state'] === 'complete', 'S3 known units should be complete');
check(abs((float)$estimate['amount'] - 0.0000058) < 0.0000000001, 'S3 reference calculation mismatch');

$partial = $catalog->estimate([
    's3.get_request' => 1,
    's3.transfer_bytes' => 4096,
]);
check($partial['state'] === 'partial', 'Known + unknown units should be partial');
check(in_array('s3.transfer_bytes', $partial['unknown_units'], true), 'Unknown unit must be disclosed');

$unpriced = $catalog->estimate(['transcribe.job_started' => 1]);
check($unpriced['state'] === 'unpriced' && $unpriced['amount'] === null, 'Transcribe without duration must stay unpriced');

$empty = $catalog->estimate([]);
check($empty['state'] === 'unpriced' && $empty['amount'] === null, 'Empty units must not become a fake zero cost');

$comprehend = $catalog->estimate(['comprehend.nlp_unit' => 3]);
check($comprehend['state'] === 'complete', 'Comprehend units should be priced');
check(abs((float)$comprehend['amount'] - 0.0003) < 0.0000000001, 'Comprehend unit calculation mismatch');

$costExplorer = $catalog->estimate(['cost_explorer.api_request' => 1]);
check($costExplorer['state'] === 'complete' && abs((float)$costExplorer['amount'] - 0.01) < 0.0000000001, 'Cost Explorer request price mismatch');

$driveOnly = $catalog->estimate(['drive.no_direct_aws_charge' => 1]);
check($driveOnly['state'] === 'complete' && (float)$driveOnly['amount'] === 0.0, 'Unmetered Drive action should be explicit zero direct AWS charge');

$correlation = ActivityCostRecorder::correlation('upload', 'secret-upload-id');
check(is_string($correlation) && !str_contains($correlation, 'secret-upload-id'), 'Correlation must hash raw identifiers');

$renderer = new ActivityCostPageRenderer();
$html = $renderer->render([
    'totals' => ['operations' => 1, 'estimated_cost' => 0.000005, 'partial_count' => 0, 'unpriced_count' => 0, 'error_count' => 0],
    'by_service' => [['service' => 'S3', 'operations' => 1, 'estimated_cost' => 0.000005]],
    'by_action' => [['action' => 'upload', 'operations' => 1, 'estimated_cost' => 0.000005]],
    'daily' => [['day' => '2026-09-10', 'operations' => 1, 'estimated_cost' => 0.000005]],
    'recent' => [[
        'CreatedAt' => '2026-09-10 10:00:00', 'Action' => 'upload', 'Service' => 'S3',
        'FileId' => 123, 'file_name' => '<script>alert(1)</script>', 'UnitsJson' => '{"s3.put_request":1}',
        'EstimatedCost' => '0.0000050000', 'Currency' => 'USD', 'PricingState' => 'complete',
        'Status' => 'ok', 'DurationMs' => 4, 'actor_user_id_' => 7,
    ]],
    'filters' => ['services' => ['S3'], 'actions' => ['upload']],
    'period' => 'month', 'period_label' => 'Mes actual', 'selected_service' => null, 'selected_action' => null,
    'real_aws' => null, 'real_aws_note' => 'Sólo atribuido', 'difference' => null,
], 7);
check(str_contains($html, 'Actividad y costos'), 'Renderer missing page title');
check(!str_contains($html, '<script>alert(1)</script>'), 'Visible file name must be escaped');
check(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Escaped visible file name missing');

fwrite(STDOUT, "OK activity-costs smoke\n");
