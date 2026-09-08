<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Ec2\Ec2Client;

final class Ec2Gateway
{
    private Ec2Client $client;

    public function __construct(string $region = '')
    {
        $region = trim($region);
        $this->client = new Ec2Client(\Config::getAwsControlClientConfig(
            $region !== '' ? ['region' => $region] : []
        ));
    }

    /** @return array<int,array<string,mixed>> */
    public function listInstances(?string $stateFilter = null): array
    {
        $instances = [];
        $params = [];

        $stateFilter = trim((string)$stateFilter);
        if ($stateFilter !== '' && $stateFilter !== 'all') {
            $params['Filters'] = [[
                'Name' => 'instance-state-name',
                'Values' => [$stateFilter],
            ]];
        }

        do {
            $result = $this->client->describeInstances($params);
            foreach (($result['Reservations'] ?? []) as $reservation) {
                foreach (($reservation['Instances'] ?? []) as $instance) {
                    $instances[] = $instance;
                }
            }
            $params['NextToken'] = $result['NextToken'] ?? null;
        } while (!empty($params['NextToken']));

        return $instances;
    }

    /** @return array<string,array<string,mixed>> */
    public function listInstancesById(): array
    {
        $out = [];
        foreach ($this->listInstances() as $instance) {
            $id = trim((string)($instance['InstanceId'] ?? ''));
            if ($id !== '') {
                $out[$id] = $instance;
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function getInstance(string $instanceId): ?array
    {
        $instanceId = trim($instanceId);
        if ($instanceId === '') {
            return null;
        }

        $result = $this->client->describeInstances([
            'InstanceIds' => [$instanceId],
        ]);

        $reservation = $result['Reservations'][0] ?? null;
        return is_array($reservation)
            ? (($reservation['Instances'][0] ?? null) ?: null)
            : null;
    }

    public function start(string $instanceId): void
    {
        $this->client->startInstances([
            'InstanceIds' => [$instanceId],
        ]);
    }

    public function stop(string $instanceId, bool $force = false): void
    {
        $this->client->stopInstances([
            'InstanceIds' => [$instanceId],
            'Force' => $force,
        ]);
    }

    public static function stateName(array $instance): string
    {
        return (string)($instance['State']['Name'] ?? 'unknown');
    }

    public static function isRunningLike(string $state): bool
    {
        return in_array($state, ['running', 'pending'], true);
    }
}
