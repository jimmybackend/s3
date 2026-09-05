<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\CostExplorer\CostExplorerClient;
use RuntimeException;

final class CostExplorerGateway
{
    private CostExplorerClient $client;

    public function __construct(array $credentials, string $region = 'us-east-1')
    {
        if (empty($credentials['key']) || empty($credentials['secret'])) {
            throw new RuntimeException('Las credenciales AWS para Cost Explorer no son válidas.');
        }

        $this->client = new CostExplorerClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => $credentials,
            'http' => [
                'connect_timeout' => 5,
                'timeout' => 15,
            ],
        ]);
    }

    public function unblendedCost(string $start, string $end): array
    {
        $result = $this->client->getCostAndUsage([
            'TimePeriod' => [
                'Start' => $start,
                'End' => $end,
            ],
            'Granularity' => 'MONTHLY',
            'Metrics' => ['UnblendedCost'],
        ]);

        $rows = $result->get('ResultsByTime');
        $amount = 0.0;
        $currency = 'USD';

        if (!empty($rows) && isset($rows[0]['Total']['UnblendedCost']['Amount'])) {
            $amount = (float)$rows[0]['Total']['UnblendedCost']['Amount'];
            $currency = (string)($rows[0]['Total']['UnblendedCost']['Unit'] ?? 'USD');
        }

        return [
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

    public function unblendedForecast(string $start, string $end): array
    {
        $result = $this->client->getCostForecast([
            'TimePeriod' => [
                'Start' => $start,
                'End' => $end,
            ],
            'Metric' => 'UNBLENDED_COST',
            'Granularity' => 'MONTHLY',
            'PredictionIntervalLevel' => 80,
        ]);

        $total = $result->get('Total');

        return [
            'amount' => !empty($total['Amount']) ? (float)$total['Amount'] : 0.0,
            'currency' => (string)($total['Unit'] ?? 'USD'),
        ];
    }
}
