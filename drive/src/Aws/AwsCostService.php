<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use DateTimeImmutable;
use DateTimeZone;

final class AwsCostService
{
    public function __construct(private CostExplorerGateway $gateway)
    {
    }

    public function summary(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $startCurrent = $now
            ->modify('first day of this month')
            ->setTime(0, 0, 0);

        $startNext = $startCurrent->modify('+1 month');
        $tomorrow = $now->modify('+1 day')->setTime(0, 0, 0);
        $endCurrent = $tomorrow < $startNext ? $tomorrow : $startNext;

        $startPrevious = $startCurrent->modify('-1 month');
        $daysElapsed = (int)$startCurrent->diff($endCurrent)->format('%a');
        $previousElapsedEnd = $startPrevious->modify('+' . $daysElapsed . ' days');

        if ($previousElapsedEnd > $startCurrent) {
            $previousElapsedEnd = $startCurrent;
        }

        $current = $this->gateway->unblendedCost(
            $startCurrent->format('Y-m-d'),
            $endCurrent->format('Y-m-d')
        );

        $actualAmount = (float)$current['amount'];
        $currency = (string)($current['currency'] ?? 'USD');

        $previousElapsedAmount = null;
        if ($previousElapsedEnd > $startPrevious) {
            $previousElapsed = $this->gateway->unblendedCost(
                $startPrevious->format('Y-m-d'),
                $previousElapsedEnd->format('Y-m-d')
            );
            $previousElapsedAmount = (float)$previousElapsed['amount'];
        }

        $forecastAmount = 0.0;
        if ($endCurrent < $startNext) {
            $forecast = $this->gateway->unblendedForecast(
                $endCurrent->format('Y-m-d'),
                $startNext->format('Y-m-d')
            );
            $forecastAmount = (float)$forecast['amount'];
            $currency = (string)($forecast['currency'] ?? $currency);
        }

        $previousFull = $this->gateway->unblendedCost(
            $startPrevious->format('Y-m-d'),
            $startCurrent->format('Y-m-d')
        );
        $previousFullAmount = (float)$previousFull['amount'];

        $predictedEndMonthAmount = $actualAmount + $forecastAmount;

        $currentPercentage = null;
        if ($previousElapsedAmount !== null && $previousElapsedAmount > 0) {
            $currentPercentage = round(($actualAmount / $previousElapsedAmount) * 100);
        }

        $forecastPercentage = null;
        if ($previousFullAmount > 0) {
            $forecastPercentage = round(($predictedEndMonthAmount / $previousFullAmount) * 100);
        }

        return [
            'ok' => true,
            'mes_actual' => 'Mes actual',
            'costo_actual' => round($actualAmount, 2),
            'porcentaje_actual' => $currentPercentage,
            'fin_mes_previsto' => 'Final de mes previsto',
            'costo_previsto' => round($predictedEndMonthAmount, 2),
            'porcentaje_previsto' => $forecastPercentage,
            'currency' => $currency,
        ];
    }
}
