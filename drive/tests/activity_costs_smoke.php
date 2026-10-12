<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Activity\AwsUnitPriceCatalog;
use ArcadeCloud\Drive\Activity\TranscriptionCostAttribution;
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

$pollyStandard = $catalog->estimate(['polly.standard_character' => 1_000_000]);
check($pollyStandard['state'] === 'complete', 'Polly standard characters should be priced');
check(abs((float)$pollyStandard['amount'] - 4.0) < 0.0000000001, 'Polly standard million-character reference mismatch');

$pollyNeural = $catalog->estimate(['polly.neural_character' => 1_000_000]);
check($pollyNeural['state'] === 'complete', 'Polly neural characters should be priced');
check(abs((float)$pollyNeural['amount'] - 16.0) < 0.0000000001, 'Polly neural million-character reference mismatch');

$pollyLongForm = $catalog->estimate(['polly.long-form_character' => 1_000_000]);
check($pollyLongForm['state'] === 'complete', 'Polly long-form characters should be priced');
check(abs((float)$pollyLongForm['amount'] - 100.0) < 0.0000000001, 'Polly long-form million-character reference mismatch');

$pollyGenerative = $catalog->estimate(['polly.generative_character' => 1_000_000]);
check($pollyGenerative['state'] === 'complete', 'Polly generative characters should be priced');
check(abs((float)$pollyGenerative['amount'] - 30.0) < 0.0000000001, 'Polly generative million-character reference mismatch');

$unpriced = $catalog->estimate(['transcribe.job_started' => 1]);
check($unpriced['state'] === 'unpriced' && $unpriced['amount'] === null, 'Transcribe without duration must stay unpriced');

$transcribeMinute = $catalog->estimate(['transcribe.standard_batch_second' => 60]);
check($transcribeMinute['state'] === 'complete', 'Transcribe standard seconds should be priced');
check(abs((float)$transcribeMinute['amount'] - 0.024) < 0.0000000001, 'Transcribe Tier-1 minute reference mismatch');

$transcribePiiMinute = $catalog->estimate([
    'transcribe.standard_batch_second' => 60,
    'transcribe.pii_redaction_second' => 60,
]);
check($transcribePiiMinute['state'] === 'complete', 'Transcribe PII known units should be priced');
check(abs((float)$transcribePiiMinute['amount'] - 0.0264) < 0.0000000001, 'Transcribe PII minute reference mismatch');

$transcript = [
    'results' => [
        'items' => [
            ['start_time' => '0.10', 'end_time' => '1.20'],
            ['start_time' => '1.30', 'end_time' => '61.10'],
        ],
        'audio_segments' => [
            ['start_time' => '0.00', 'end_time' => '61.25'],
        ],
    ],
];
$attribution = TranscriptionCostAttribution::fromResult($transcript, []);
check(abs((float)$attribution['duration_seconds_observed'] - 61.25) < 0.0001, 'Transcribe duration extraction mismatch');
check((int)$attribution['billable_seconds_reference'] === 62, 'Transcribe seconds must round up to whole seconds');
check(($attribution['units']['transcribe.standard_batch_second'] ?? 0) === 62, 'Transcribe standard unit quantity mismatch');

$shortAttribution = TranscriptionCostAttribution::fromResult([
    'results' => ['items' => [['end_time' => '3.2']]],
], []);
check((int)$shortAttribution['billable_seconds_reference'] === 15, 'Transcribe pricing reference minimum should be 15 seconds');

$partialAddOn = TranscriptionCostAttribution::fromResult($transcript, [
    'ModelSettings' => ['LanguageModelName' => 'custom-model'],
]);
$partialAddOnEstimate = $catalog->estimate($partialAddOn['units']);
check($partialAddOnEstimate['state'] === 'partial', 'Unverified Transcribe add-on must keep pricing partial');
check(in_array('transcribe.custom_language_model_second', $partialAddOnEstimate['unknown_units'], true), 'Unverified CLM unit must be disclosed');

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
    'recent_pagination' => ['page' => 2, 'per_page' => 25, 'total_items' => 70, 'total_pages' => 3, 'from' => 26, 'to' => 50],
    'filters' => ['services' => ['S3'], 'actions' => ['upload']],
    'period' => 'month', 'period_label' => 'Mes actual', 'selected_service' => 'S3', 'selected_action' => 'upload',
    'real_aws' => null, 'real_aws_note' => 'Sólo atribuido', 'difference' => null,
], 7);
check(str_contains($html, 'Actividad y costos'), 'Renderer missing page title');
check(!str_contains($html, '<script>alert(1)</script>'), 'Visible file name must be escaped');
check(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Escaped visible file name missing');
check(str_contains($html, 'Mostrando 26–50 de 70 eventos'), 'Paginated activity range missing');
check(str_contains($html, 'Página 2 de 3'), 'Pagination label missing');
check(str_contains($html, 'page=3'), 'Next-page link missing');
check(str_contains($html, 'service=S3'), 'Pagination must preserve service filter');
check(str_contains($html, 'action=upload'), 'Pagination must preserve action filter');
$servicePos = strpos($html, 'Costo atribuido por servicio');
$actionPos = strpos($html, 'Costo atribuido por operación');
$recentPos = strpos($html, 'Actividad reciente');
$dailyPos = strpos($html, 'Costo diario atribuido');
check($servicePos !== false && $actionPos !== false && $recentPos !== false && $dailyPos !== false, 'Activity sections missing');
check($servicePos < $actionPos && $actionPos < $recentPos && $recentPos < $dailyPos, 'Activity sections must be stacked in the requested order');
check(str_contains($html, 'activity-breakdown-stack'), 'Stacked breakdown wrapper missing');
check(!str_contains($html, 'activity-breakdown-row'), 'Old flex breakdown row must not render');
check(str_contains($html, 'activity-costs.css?v=20260923-3'), 'Activity CSS cache-busting version missing');

// Owner-only breakdown: visible for an authorized VM and absent for ordinary users.
$ownerVm = [
    'totals' => ['operations' => 1, 'estimated_cost' => 0.1],
    'by_service' => [], 'by_action' => [], 'daily' => [], 'recent' => [],
    'recent_pagination' => [], 'filters' => ['services' => [], 'actions' => []],
    'period' => 'month', 'period_label' => 'Mes actual',
    'selected_service' => null, 'selected_action' => null,
    'real_aws' => null, 'real_aws_note' => '', 'difference' => null,
    'owner_breakdown' => [
        'users' => [['user_id' => 7, 'operations' => 2, 'service_events' => 3,
            'estimated_cost' => 0.12, 'partial_count' => 1, 'unpriced_count' => 1, 'error_count' => 0]],
        'services' => [['user_id' => 7, 'service' => 'Bedrock', 'operations' => 2,
            'estimated_cost' => 0.12, 'incomplete_count' => 1]]
    ]
];
$ownerHtml = $renderer->render($ownerVm, 1);
check(str_contains($ownerHtml, 'Consumo AWS atribuido por usuario'), 'Owner aggregation missing');
check(str_contains($ownerHtml, 'Usuario #7'), 'Owner aggregation user absent');
check(str_contains($ownerHtml, 'Bedrock'), 'Owner AWS service breakdown absent');
unset($ownerVm['owner_breakdown']);
$regularHtml = $renderer->render($ownerVm, 7);
check(!str_contains($regularHtml, 'Consumo AWS atribuido por usuario'), 'Cross-user aggregation leaked to regular view');

$accountingVm = [
    'totals' => ['operations' => 1, 'estimated_cost' => 0.5],
    'by_service' => [], 'by_action' => [], 'daily' => [], 'recent' => [],
    'recent_pagination' => [], 'filters' => ['services' => [], 'actions' => []],
    'period' => 'month', 'period_label' => 'Mes actual',
    'selected_service' => null, 'selected_action' => null,
    'real_aws' => null, 'real_aws_note' => '', 'difference' => null,
    'owner_accounting' => [
        'users' => [['user_id' => 3, 'bytes' => 1024, 'files' => 1,
            'direct' => 0.2, 's3_estimate' => null, 'ec2_share' => null, 'allocated_total' => 0.2]],
        'totals' => ['direct' => 0.2, 's3' => null, 'ec2' => null,
            'allocated' => 0.2, 'real_aws' => 1.0, 'unassigned' => 0.8],
        'policy' => ['s3_usd_gb_month' => null, 'ec2_usd_month' => null,
            'snapshot_available' => true],
    ],
];
$accountingHtml = $renderer->render($accountingVm, 1);
check(str_contains($accountingHtml, 'Contabilidad AWS por usuario'), 'Accounting section missing');
check(str_contains($accountingHtml, 'Pendiente de configurar'), 'Missing rate must not show as zero');
unset($accountingVm['owner_accounting']);
check(!str_contains($renderer->render($accountingVm, 3), 'Contabilidad AWS por usuario'),
    'Regular user accounting section should not be present');

// Micro-costs must not disappear in presentation at 8-digit precision.
$microVm = [
    'totals' => ['operations' => 1, 'estimated_cost' => 0.000000000001],
    'by_service' => [['service' => 'S3', 'operations' => 1, 'estimated_cost' => 0.000000000001]],
    'by_action' => [], 'daily' => [], 'recent' => [], 'recent_pagination' => [],
    'filters' => ['services' => [], 'actions' => []], 'period' => 'month',
    'period_label' => 'Mes actual', 'selected_service' => null, 'selected_action' => null,
    'real_aws' => null, 'real_aws_note' => '', 'difference' => null,
];
$ordinaryVm = $microVm;
$ordinaryVm['totals']['estimated_cost'] = 15.99;
$ordinaryHtml = $renderer->render($ordinaryVm, 1);
check(str_contains($ordinaryHtml, '$15.99'), 'Normal USD cost must show ordinary cents');
check(!str_contains($ordinaryHtml, '15.9900000000000002'), 'Float noise must not be rendered');
$microHtml = $renderer->render($microVm, 1);
check(str_contains($microHtml, '$0.000000000001'), 'Sub-8-decimal USD cost must be visible');
check(!str_contains($microHtml, '$0.00000000</div>'), 'Micro-cost must not appear as zero');
$negativeVm = $microVm;
$negativeVm['totals']['estimated_cost'] = -0.000000000001;
check(str_contains($renderer->render($negativeVm, 1), '-$0.000000000001'), 'Negative reconciliation formatting precision');

fwrite(STDOUT, "OK activity-costs smoke\n");
