<?php
declare(strict_types=1);

ob_start();
session_start();

header('Content-Type: application/json; charset=UTF-8');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app_bootstrap.php';

use Aws\CostExplorer\CostExplorerClient;
use Aws\Exception\AwsException;
use Aws\Sts\StsClient;

try {
    if (empty($_SESSION['usuario'])) {
        throw new Exception('Sesión inválida.');
    }

    // Libera la sesión para evitar bloqueos mientras se consulta AWS
    session_write_close();

    // Evita que el script quede abierto demasiado tiempo
    set_time_limit(20);

    if (!class_exists('Config')) {
        throw new Exception('No se encontró la clase Config.');
    }

    if (!method_exists('Config', 'getAwsCredentials')) {
        throw new Exception('La clase Config no tiene el método getAwsCredentials().');
    }

    $credentials = Config::getAwsCredentials();

    if (
        !is_array($credentials) ||
        empty($credentials['key']) ||
        empty($credentials['secret'])
    ) {
        throw new Exception('Config::getAwsCredentials() no devolvió credenciales válidas.');
    }

    $region = 'us-east-1';
    $debugAwsIdentity = false; // Cambia a true solo si quieres ver el ARN real del usuario AWS

    $debugKey = substr((string)$credentials['key'], 0, 4) . '...' . substr((string)$credentials['key'], -4);

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $startCurrent = $now
        ->modify('first day of this month')
        ->setTime(0, 0, 0);

    $startNext = $startCurrent->modify('+1 month');

    $tomorrow = $now
        ->modify('+1 day')
        ->setTime(0, 0, 0);

    // End exclusivo en Cost Explorer
    $endCurrent = ($tomorrow < $startNext) ? $tomorrow : $startNext;

    $startPrev = $startCurrent->modify('-1 month');

    $daysElapsed = (int)$startCurrent->diff($endCurrent)->format('%a');

    $prevElapsedEnd = $startPrev->modify('+' . $daysElapsed . ' days');
    if ($prevElapsedEnd > $startCurrent) {
        $prevElapsedEnd = $startCurrent;
    }

    $clientConfig = [
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => $credentials,
        'http' => [
            'connect_timeout' => 5,
            'timeout' => 15,
        ],
    ];

    $ce = new CostExplorerClient($clientConfig);

    $awsIdentity = null;

    if ($debugAwsIdentity === true) {
        $sts = new StsClient($clientConfig);
        $caller = $sts->getCallerIdentity();

        $awsIdentity = [
            'access_key' => $debugKey,
            'account'    => (string)$caller->get('Account'),
            'arn'        => (string)$caller->get('Arn'),
            'user_id'    => (string)$caller->get('UserId'),
        ];
    }

    $actualResp = $ce->getCostAndUsage([
        'TimePeriod' => [
            'Start' => $startCurrent->format('Y-m-d'),
            'End'   => $endCurrent->format('Y-m-d'),
        ],
        'Granularity' => 'MONTHLY',
        'Metrics'     => ['UnblendedCost'],
    ]);

    $actualAmount = 0.0;
    $currency = 'USD';

    $actualByTime = $actualResp->get('ResultsByTime');

    if (
        !empty($actualByTime) &&
        isset($actualByTime[0]['Total']['UnblendedCost']['Amount'])
    ) {
        $actualAmount = (float)$actualByTime[0]['Total']['UnblendedCost']['Amount'];
        $currency = (string)($actualByTime[0]['Total']['UnblendedCost']['Unit'] ?? 'USD');
    }

    $prevAmount = null;

    if ($prevElapsedEnd > $startPrev) {
        $prevResp = $ce->getCostAndUsage([
            'TimePeriod' => [
                'Start' => $startPrev->format('Y-m-d'),
                'End'   => $prevElapsedEnd->format('Y-m-d'),
            ],
            'Granularity' => 'MONTHLY',
            'Metrics'     => ['UnblendedCost'],
        ]);

        $prevByTime = $prevResp->get('ResultsByTime');

        if (
            !empty($prevByTime) &&
            isset($prevByTime[0]['Total']['UnblendedCost']['Amount'])
        ) {
            $prevAmount = (float)$prevByTime[0]['Total']['UnblendedCost']['Amount'];
        }
    }

    $forecastAmount = 0.0;

    if ($endCurrent < $startNext) {
        $forecastResp = $ce->getCostForecast([
            'TimePeriod' => [
                'Start' => $endCurrent->format('Y-m-d'),
                'End'   => $startNext->format('Y-m-d'),
            ],
            'Metric' => 'UNBLENDED_COST',
            'Granularity' => 'MONTHLY',
            'PredictionIntervalLevel' => 80,
        ]);

        $forecastTotal = $forecastResp->get('Total');

        if (!empty($forecastTotal['Amount'])) {
            $forecastAmount = (float)$forecastTotal['Amount'];
            $currency = (string)($forecastTotal['Unit'] ?? $currency);
        }
    }

    $predictedEndMonthAmount = $actualAmount + $forecastAmount;

    $prevFullMonthResp = $ce->getCostAndUsage([
        'TimePeriod' => [
            'Start' => $startPrev->format('Y-m-d'),
            'End'   => $startCurrent->format('Y-m-d'),
        ],
        'Granularity' => 'MONTHLY',
        'Metrics'     => ['UnblendedCost'],
    ]);

    $prevFullMonthAmount = null;
    $prevFullByTime = $prevFullMonthResp->get('ResultsByTime');

    if (
        !empty($prevFullByTime) &&
        isset($prevFullByTime[0]['Total']['UnblendedCost']['Amount'])
    ) {
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

    if (ob_get_length()) {
        ob_clean();
    }

    $response = [
        'ok' => true,
        'mes_actual' => 'Mes actual',
        'costo_actual' => round($actualAmount, 2),
        'porcentaje_actual' => $porcentajeActual,
        'fin_mes_previsto' => 'Final de mes previsto',
        'costo_previsto' => round($predictedEndMonthAmount, 2),
        'porcentaje_previsto' => $porcentajePrevisto,
        'currency' => $currency,
        'debug' => [
            'aws_key' => $debugKey,
            'time_period_actual' => [
                'start' => $startCurrent->format('Y-m-d'),
                'end'   => $endCurrent->format('Y-m-d'),
            ],
            'time_period_forecast' => [
                'start' => $endCurrent->format('Y-m-d'),
                'end'   => $startNext->format('Y-m-d'),
            ],
        ],
    ];

    if ($awsIdentity !== null) {
        $response['debug_aws'] = $awsIdentity;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (AwsException $e) {
    if (ob_get_length()) {
        ob_clean();
    }

    $awsMessage = $e->getAwsErrorMessage();
    if (!$awsMessage) {
        $awsMessage = $e->getMessage();
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'AWS Error: ' . $awsMessage,
        'aws_code' => $e->getAwsErrorCode(),
        'aws_type' => $e->getAwsErrorType(),
        'detalle' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}