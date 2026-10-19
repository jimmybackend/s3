<?php
declare(strict_types=1);
$service = file_get_contents(__DIR__ . '/../src/Application/ZipLambdaExplorerService.php');
$router = file_get_contents(__DIR__ . '/../src/Application/ZipExplorerService.php');
$pricing = json_decode((string)file_get_contents(__DIR__ . '/../config/activity-cost-pricing.json'), true);
foreach (['requireReadableByKey', 'ActivityCostRecorder::fromDatabase', "'InvocationType' => 'RequestResponse'", "zip.lambda.", "Referrer-Policy", "parse_url"] as $needle) {
    if (!str_contains($service, $needle)) throw new RuntimeException('Contrato ZIP Lambda incompleto: ' . $needle);
}
if (!str_contains($router, 'ZipLambdaExplorerService::enabled()')) throw new RuntimeException('No hay fallback ZIP.');
if (!isset($pricing['rates']['lambda.request'], $pricing['rates']['lambda.gb_second_estimate'])) throw new RuntimeException('Tarifa Lambda ausente.');
echo "zip lambda integration contract ok\n";
