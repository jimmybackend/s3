<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use DateTimeImmutable;
use DateTimeZone;

final class AwsCostService
{
    public function __construct(
        private CostExplorerGateway $gateway,
        private string $cacheDirectory = ''
    ) {
        if ($this->cacheDirectory === '') {
            $this->cacheDirectory = sys_get_temp_dir() . '/arcadecloud-drive-cost-explorer-cache';
        }
    }

    public function summary(?DateTimeImmutable $now = null): array
    {
        $useCache = $now === null;
        if ($useCache) {
            $cached = $this->readCache();
            if ($cached !== null) {
                $cached['api_requests'] = 0;
                $cached['cached'] = true;
                return $cached;
            }
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $apiRequests = 0;
        $startCurrent = $now->modify('first day of this month')->setTime(0, 0, 0);
        $startNext = $startCurrent->modify('+1 month');
        $tomorrow = $now->modify('+1 day')->setTime(0, 0, 0);
        $endCurrent = $tomorrow < $startNext ? $tomorrow : $startNext;
        $startPrevious = $startCurrent->modify('-1 month');
        $daysElapsed = (int)$startCurrent->diff($endCurrent)->format('%a');
        $previousElapsedEnd = $startPrevious->modify('+' . $daysElapsed . ' days');
        if ($previousElapsedEnd > $startCurrent) $previousElapsedEnd = $startCurrent;

        $current = $this->gateway->unblendedCost($startCurrent->format('Y-m-d'), $endCurrent->format('Y-m-d'));
        $apiRequests++;
        $actualAmount = (float)$current['amount'];
        $currency = (string)($current['currency'] ?? 'USD');

        $previousElapsedAmount = null;
        if ($previousElapsedEnd > $startPrevious) {
            $previousElapsed = $this->gateway->unblendedCost($startPrevious->format('Y-m-d'), $previousElapsedEnd->format('Y-m-d'));
            $apiRequests++;
            $previousElapsedAmount = (float)$previousElapsed['amount'];
        }

        $forecastAmount = 0.0;
        if ($endCurrent < $startNext) {
            $forecast = $this->gateway->unblendedForecast($endCurrent->format('Y-m-d'), $startNext->format('Y-m-d'));
            $apiRequests++;
            $forecastAmount = (float)$forecast['amount'];
            $currency = (string)($forecast['currency'] ?? $currency);
        }

        $previousFull = $this->gateway->unblendedCost($startPrevious->format('Y-m-d'), $startCurrent->format('Y-m-d'));
        $apiRequests++;
        $previousFullAmount = (float)$previousFull['amount'];
        $predictedEndMonthAmount = $actualAmount + $forecastAmount;
        $currentPercentage = $previousElapsedAmount !== null && $previousElapsedAmount > 0 ? round(($actualAmount / $previousElapsedAmount) * 100) : null;
        $forecastPercentage = $previousFullAmount > 0 ? round(($predictedEndMonthAmount / $previousFullAmount) * 100) : null;

        $summary = [
            'ok' => true,
            'mes_actual' => 'Mes actual',
            'costo_actual' => round($actualAmount, 2),
            'porcentaje_actual' => $currentPercentage,
            'fin_mes_previsto' => 'Final de mes previsto',
            'costo_previsto' => round($predictedEndMonthAmount, 2),
            'porcentaje_previsto' => $forecastPercentage,
            'currency' => $currency,
            'api_requests' => $apiRequests,
            'cached' => false,
        ];

        if ($useCache) $this->writeCache($summary);
        return $summary;
    }

    private function cachePath(): string
    {
        return rtrim($this->cacheDirectory, '/\\') . '/summary.json';
    }

    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path) || (time() - (int)@filemtime($path)) >= 3600) return null;
        $decoded = json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) && ($decoded['ok'] ?? false) === true ? $decoded : null;
    }

    private function writeCache(array $summary): void
    {
        $path = $this->cachePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return;
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) @unlink($tmp);
    }
}
