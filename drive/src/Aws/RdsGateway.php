<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Exception\AwsException;
use Aws\Rds\RdsClient;
use RuntimeException;

final class RdsGateway
{
    private RdsClient $client;

    public function __construct(string $region = '')
    {
        $region = trim($region);
        $this->client = new RdsClient(\Config::getAwsClientConfig(
            $region !== '' ? ['region' => $region] : []
        ));
    }

    /** @return array{type:string,data:array<string,mixed>}|null */
    public function getDatabaseTarget(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $instance = $this->describeInstanceOrNull($id);
        if ($instance !== null) {
            return ['type' => 'instance', 'data' => $instance];
        }

        $cluster = $this->describeClusterOrNull($id);
        if ($cluster !== null) {
            return ['type' => 'cluster', 'data' => $cluster];
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listConfiguredDatabases(array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id === '') {
                continue;
            }

            try {
                $target = $this->getDatabaseTarget($id);
                if ($target === null) {
                    $rows[] = $this->notFoundRow($id);
                    continue;
                }
                $rows[] = $this->normalizeTarget($id, $target);
            } catch (AwsException $error) {
                $rows[] = $this->errorRow(
                    $id,
                    ($error->getAwsErrorCode() ?: 'AWS') . ': ' .
                    ($error->getAwsErrorMessage() ?: $error->getMessage())
                );
            } catch (\Throwable $error) {
                $rows[] = $this->errorRow($id, $error->getMessage());
            }
        }
        return $rows;
    }

    public function startDatabase(string $id, string $type): void
    {
        if ($type === 'instance') {
            $this->client->startDBInstance(['DBInstanceIdentifier' => $id]);
            return;
        }
        if ($type === 'cluster') {
            $this->client->startDBCluster(['DBClusterIdentifier' => $id]);
            return;
        }
        throw new RuntimeException("Tipo de base de datos no soportado para START: {$type}");
    }

    public function stopDatabase(string $id, string $type): void
    {
        if ($type === 'instance') {
            $this->client->stopDBInstance(['DBInstanceIdentifier' => $id]);
            return;
        }
        if ($type === 'cluster') {
            $this->client->stopDBCluster(['DBClusterIdentifier' => $id]);
            return;
        }
        throw new RuntimeException("Tipo de base de datos no soportado para STOP: {$type}");
    }

    public function statusFromTarget(array $target): string
    {
        if (($target['type'] ?? '') === 'instance') {
            return (string)($target['data']['DBInstanceStatus'] ?? 'unknown');
        }
        if (($target['type'] ?? '') === 'cluster') {
            return (string)($target['data']['Status'] ?? 'unknown');
        }
        return 'unknown';
    }

    public function isStartable(string $status): bool
    {
        return $status === 'stopped';
    }

    public function isStoppable(string $status): bool
    {
        return $status === 'available';
    }

    public function stateClass(string $status): string
    {
        if ($status === 'available') return 'state-running';
        if ($status === 'stopped') return 'state-stopped';
        if (in_array($status, ['not_found', 'error'], true)) return 'state-error';
        return 'state-other';
    }

    /** @return array<string,mixed> */
    public function normalizeTarget(string $id, array $target): array
    {
        if (($target['type'] ?? '') === 'instance') {
            $db = $target['data'];
            return [
                'id' => $id,
                'found' => true,
                'aws_type' => 'RDS Instance',
                'target_type' => 'instance',
                'status' => (string)($db['DBInstanceStatus'] ?? 'unknown'),
                'engine' => (string)($db['Engine'] ?? ''),
                'class' => (string)($db['DBInstanceClass'] ?? ''),
                'endpoint' => (string)($db['Endpoint']['Address'] ?? ''),
                'reader_endpoint' => '',
                'port' => (string)($db['Endpoint']['Port'] ?? ''),
                'az' => (string)($db['AvailabilityZone'] ?? ''),
                'multi_az' => !empty($db['MultiAZ']) ? 'Sí' : 'No',
                'error' => '',
            ];
        }

        if (($target['type'] ?? '') === 'cluster') {
            $cluster = $target['data'];
            $azs = $cluster['AvailabilityZones'] ?? [];
            return [
                'id' => $id,
                'found' => true,
                'aws_type' => 'Aurora / DB Cluster',
                'target_type' => 'cluster',
                'status' => (string)($cluster['Status'] ?? 'unknown'),
                'engine' => (string)($cluster['Engine'] ?? ''),
                'class' => 'cluster',
                'endpoint' => (string)($cluster['Endpoint'] ?? ''),
                'reader_endpoint' => (string)($cluster['ReaderEndpoint'] ?? ''),
                'port' => (string)($cluster['Port'] ?? ''),
                'az' => is_array($azs) ? implode(', ', array_map('strval', $azs)) : '',
                'multi_az' => is_array($azs) && count($azs) > 1 ? 'Sí' : 'No',
                'error' => '',
            ];
        }

        return $this->notFoundRow($id);
    }

    private function describeInstanceOrNull(string $id): ?array
    {
        try {
            $result = $this->client->describeDBInstances([
                'DBInstanceIdentifier' => $id,
            ]);
            return $result['DBInstances'][0] ?? null;
        } catch (AwsException $error) {
            $code = (string)($error->getAwsErrorCode() ?: '');
            if (in_array($code, ['DBInstanceNotFound', 'DBInstanceNotFoundFault'], true)) {
                return null;
            }
            throw $error;
        }
    }

    private function describeClusterOrNull(string $id): ?array
    {
        try {
            $result = $this->client->describeDBClusters([
                'DBClusterIdentifier' => $id,
            ]);
            return $result['DBClusters'][0] ?? null;
        } catch (AwsException $error) {
            $code = (string)($error->getAwsErrorCode() ?: '');
            if (in_array($code, ['DBClusterNotFound', 'DBClusterNotFoundFault'], true)) {
                return null;
            }
            throw $error;
        }
    }

    private function notFoundRow(string $id): array
    {
        return [
            'id' => $id,
            'found' => false,
            'aws_type' => 'No encontrada',
            'target_type' => '',
            'status' => 'not_found',
            'engine' => '',
            'class' => '',
            'endpoint' => '',
            'reader_endpoint' => '',
            'port' => '',
            'az' => '',
            'multi_az' => '',
            'error' => '',
        ];
    }

    private function errorRow(string $id, string $message): array
    {
        $row = $this->notFoundRow($id);
        $row['aws_type'] = 'Error';
        $row['status'] = 'error';
        $row['error'] = $message;
        return $row;
    }
}
