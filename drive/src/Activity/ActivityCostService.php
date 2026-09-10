<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Activity;

use ArcadeCloud\Drive\Aws\CostExplorerGateway;
use DateTimeImmutable;
use DateTimeZone;

final class ActivityCostService
{
    public function __construct(
        private ActivityCostRepository $repository,
        private CostExplorerGateway $costExplorer,
        private string $cacheDirectory = ''
    ) {
        if ($this->cacheDirectory === '') {
            $this->cacheDirectory = sys_get_temp_dir() . '/arcadecloud-drive-cost-explorer-cache';
        }
    }

    public function dashboard(
        int $userId,
        string $period,
        ?string $service,
        ?string $action,
        bool $canViewRealAws
    ): array {
        [$period, $start, $end, $label] = $this->period($period);
        $service = $this->filter($service);
        $action = $this->filter($action);

        $data = $this->repository->dashboard(
            $userId,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            $service,
            $action
        );

        $estimated = (float)($data['totals']['estimated_cost'] ?? 0.0);
        $real = null;
        $realNote = 'El costo REAL AWS es de toda la cuenta y sólo se muestra al propietario autorizado.';

        if ($canViewRealAws && $service === null && $action === null) {
            try {
                $real = $this->cachedRealAws($start, $end);
                $realNote = 'REAL AWS: UnblendedCost de Cost Explorer para toda la cuenta. Puede tener retraso de facturación.';
            } catch (\Throwable $e) {
                $real = ['available' => false, 'error' => $e->getMessage()];
                $realNote = 'Cost Explorer no estuvo disponible; la actividad atribuida continúa siendo utilizable.';
            }
        } elseif ($canViewRealAws && ($service !== null || $action !== null)) {
            $realNote = 'REAL AWS se oculta al filtrar por servicio u operación porque Cost Explorer representa la cuenta completa, no ese subconjunto del Drive.';
        }

        $difference = null;
        if (is_array($real) && ($real['available'] ?? false) === true) {
            $difference = (float)$real['amount'] - $estimated;
        }

        return $data + [
            'period' => $period,
            'period_label' => $label,
            'start' => $start->format(DATE_ATOM),
            'end' => $end->format(DATE_ATOM),
            'selected_service' => $service,
            'selected_action' => $action,
            'real_aws' => $real,
            'real_aws_note' => $realNote,
            'difference' => $difference,
        ];
    }

    private function cachedRealAws(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        $cacheKey = hash('sha256', $startDate . '|' . $endDate);
        $path = rtrim($this->cacheDirectory, '/\\') . '/' . $cacheKey . '.json';

        if (is_file($path) && (time() - (int)filemtime($path)) < 3600) {
            $cached = json_decode((string)file_get_contents($path), true);
            if (is_array($cached) && isset($cached['amount'], $cached['currency'])) {
                return [
                    'available' => true,
                    'amount' => (float)$cached['amount'],
                    'currency' => (string)$cached['currency'],
                    'source' => 'AWS Cost Explorer / UnblendedCost',
                    'cached' => true,
                ];
            }
        }

        $result = $this->costExplorer->unblendedCost($startDate, $endDate);
        $payload = [
            'amount' => (float)($result['amount'] ?? 0.0),
            'currency' => (string)($result['currency'] ?? 'USD'),
            'fetched_at' => gmdate(DATE_ATOM),
        ];
        $this->writeCache($path, $payload);

        return [
            'available' => true,
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'source' => 'AWS Cost Explorer / UnblendedCost',
            'cached' => false,
        ];
    }

    private function writeCache(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return;
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) @unlink($tmp);
    }

    private function period(string $period): array
    {
        $tz = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);
        $today = $now->setTime(0, 0, 0);
        $month = $today->modify('first day of this month');

        return match ($period) {
            'today' => ['today', $today, $today->modify('+1 day'), 'Hoy'],
            '7d' => ['7d', $today->modify('-6 days'), $today->modify('+1 day'), 'Últimos 7 días'],
            'previous_month' => ['previous_month', $month->modify('-1 month'), $month, 'Mes anterior'],
            default => ['month', $month, $today->modify('+1 day'), 'Mes actual'],
        };
    }

    private function filter(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value) === 1 ? $value : null;
    }
}
