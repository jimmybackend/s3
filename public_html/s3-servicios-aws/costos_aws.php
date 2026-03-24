<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

use Aws\CostExplorer\CostExplorerClient;

try {
    if (empty($_SESSION['usuario'])) {
        throw new Exception('Sesión inválida.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $startCurrent = $now->modify('first day of this month')->setTime(0, 0, 0);
    $startNext = $startCurrent->modify('+1 month');
    $tomorrow = $now->modify('+1 day')->setTime(0, 0, 0);

    // End es exclusivo para Cost Explorer.
    $endCurrent = $tomorrow < $startNext ? $tomorrow : $startNext;

    $startPrev = $startCurrent->modify('-1 month');
    $daysElapsed = (int)$startCurrent->diff($endCurrent)->format('%a');
    $prevElapsedEnd = $startPrev->modify('+' . $daysElapsed . ' days');
    if ($prevElapsedEnd > $startCurrent) {
        $prevElapsedEnd = $startCurrent;
    }

    $ce = new CostExplorerClient([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => Config::getAwsCredentials(),
    ]);

    $actualResp = $ce->getCostAndUsage([
        'TimePeriod' => [
            'Start' => $startCurrent->format('Y-m-d'),
            'End' => $endCurrent->format('Y-m-d'),
        ],
        'Granularity' => 'MONTHLY',
        'Metrics' => ['UnblendedCost'],
    ]);

    $actualAmount = 0.0;
    $currency = 'USD';
    $actualByTime = $actualResp->get('ResultsByTime');
    if (!empty($actualByTime[0]['Total']['UnblendedCost']['Amount'])) {
        $actualAmount = (float)$actualByTime[0]['Total']['UnblendedCost']['Amount'];
        $currency = (string)($actualByTime[0]['Total']['UnblendedCost']['Unit'] ?? 'USD');
    }

    $prevAmount = null;
    if ($prevElapsedEnd > $startPrev) {
        $prevResp = $ce->getCostAndUsage([
            'TimePeriod' => [
                'Start' => $startPrev->format('Y-m-d'),
                'End' => $prevElapsedEnd->format('Y-m-d'),
            ],
            'Granularity' => 'MONTHLY',
            'Metrics' => ['UnblendedCost'],
        ]);

        $prevByTime = $prevResp->get('ResultsByTime');
        if (!empty($prevByTime[0]['Total']['UnblendedCost']['Amount'])) {
            $prevAmount = (float)$prevByTime[0]['Total']['UnblendedCost']['Amount'];
        }
    }

    $forecastResp = $ce->getCostForecast([
        'TimePeriod' => [
            'Start' => $endCurrent->format('Y-m-d'),
            'End' => $startNext->format('Y-m-d'),
        ],
        'Metric' => 'UNBLENDED_COST',
        'Granularity' => 'MONTHLY',
        'PredictionIntervalLevel' => 80,
    ]);

    $forecastAmount = 0.0;
    $forecastTotal = $forecastResp->get('Total');
    if (!empty($forecastTotal['Amount'])) {
        $forecastAmount = (float)$forecastTotal['Amount'];
        $currency = (string)($forecastTotal['Unit'] ?? $currency);
    }

    $predictedEndMonthAmount = $actualAmount + $forecastAmount;

    $prevFullMonthResp = $ce->getCostAndUsage([
        'TimePeriod' => [
            'Start' => $startPrev->format('Y-m-d'),
            'End' => $startCurrent->format('Y-m-d'),
        ],
        'Granularity' => 'MONTHLY',
        'Metrics' => ['UnblendedCost'],
    ]);

    $prevFullMonthAmount = null;
    $prevFullByTime = $prevFullMonthResp->get('ResultsByTime');
    if (!empty($prevFullByTime[0]['Total']['UnblendedCost']['Amount'])) {
        $prevFullMonthAmount = (float)$prevFullByTime[0]['Total']['UnblendedCost']['Amount'];
    }

    $porcentajeActual = null;
    if ($prevAmount !== null && $prevAmount > 0) {
        $porcentajeActual = round(($actualAmount / $prevAmount) * 100);
    }

    $porcentajePrevisto = null;
    if ($prevFullMonthAmount !== null && $prevFullMonthAmount > 0) {
        $porcentajePrevisto = round(($predictedEndMonthAmount / $prevFullMonthAmount) * 100);
    }

    echo json_encode([
        'ok' => true,
        'mes_actual' => 'Mes actual',
        'costo_actual' => round($actualAmount, 2),
        'porcentaje_actual' => $porcentajeActual,
        'fin_mes_previsto' => 'Final de mes previsto',
        'costo_previsto' => round($predictedEndMonthAmount, 2),
        'porcentaje_previsto' => $porcentajePrevisto,
        'currency' => $currency,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'No se pudo obtener los costos de AWS. Verifica permisos de Cost Explorer (ce:GetCostAndUsage y ce:GetCostForecast). Detalle: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
