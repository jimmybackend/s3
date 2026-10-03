<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\NodeIdentityService;
use ArcadeCloud\Drive\System\NodeCapabilityService;
use mysqli;
use Throwable;

final class ProductionPreflightService
{
    private const REQUIRED_FEDERATION_TABLES = [
        'FederationNodes',
        'FederationEvents',
        'FederationClocks',
        'FederatedResources',
        'FederationResourceLocations',
        'FederationResourceDeliveries',
        'FederationResourceDeliverySources',
        'FederationAccessRequests',
        'FederationShares',
        'FederationReplicaJobs',
        'FederationReplicaObjects',
        'FederationIngressQueue',
        'FederationAbuseReports',
        'FederationModerationBlocks',
        'FederationModerationActions',
    ];

    private const REQUIRED_RUNTIME_FILES = [
        'drive/so.php',
        'drive/database-backup.php',
        'drive/node-status.php',
        'drive/federationcloud/node.php',
        'drive/federationcloud/search.php',
        'drive/federationcloud/resource.php',
        'drive/federationcloud/replica.php',
        'drive/federationcloud/moderation.php',
        'drive/bin/federation_sync.php',
        'drive/bin/federation_catalog_migrate.php',
        'drive/src/Admin/DatabaseSqlDumpWriter.php',
    ];

    public function __construct(
        private mysqli $db,
        private string $projectRoot
    ) {
        $this->projectRoot = rtrim($this->projectRoot, '/');
    }

    public function run(): array
    {
        $checks = [];
        $this->check($checks, 'database', fn(): array => $this->databaseCheck());
        $this->check($checks, 'federation_config', fn(): array => $this->federationConfigCheck());
        $this->check($checks, 'federation_identity', fn(): array => $this->federationIdentityCheck());
        $this->check($checks, 'federation_schema', fn(): array => $this->federationSchemaCheck());
        $this->check($checks, 'runtime_files', fn(): array => $this->runtimeFilesCheck());
        $this->check($checks, 'node_capacity', fn(): array => $this->nodeCapacityCheck());

        $failed = array_values(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'fail'));
        $warning = array_values(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'warning'));

        return [
            'ok' => $failed === [],
            'status' => $failed !== [] ? 'fail' : ($warning !== [] ? 'warning' : 'ready'),
            'generated_at' => gmdate(DATE_ATOM),
            'checks' => $checks,
            'summary' => [
                'total' => count($checks),
                'failed' => count($failed),
                'warnings' => count($warning),
                'ready' => count($checks) - count($failed) - count($warning),
            ],
        ];
    }

    private function check(array &$checks, string $name, callable $callback): void
    {
        try {
            $result = $callback();
            $checks[] = ['name' => $name] + $result;
        } catch (Throwable $e) {
            $checks[] = [
                'name' => $name,
                'status' => 'fail',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function databaseCheck(): array
    {
        if (!$this->db->ping()) {
            return ['status' => 'fail', 'message' => 'MySQL/MariaDB no responde.'];
        }

        $database = '';
        $result = $this->db->query('SELECT DATABASE() AS db_name');
        if ($result) {
            $row = $result->fetch_assoc() ?: [];
            $database = trim((string)($row['db_name'] ?? ''));
            $result->free();
        }

        return [
            'status' => $database !== '' ? 'ready' : 'warning',
            'message' => $database !== '' ? 'Conexión activa a la base configurada.' : 'Conexión activa, pero no se pudo identificar DATABASE().',
            'database' => $database,
        ];
    }

    private function federationConfigCheck(): array
    {
        $config = FederationConfig::fromEnvironment();
        return [
            'status' => $config->enabled() ? 'ready' : 'warning',
            'message' => $config->enabled() ? 'FederationCloud está habilitado.' : 'FederationCloud está configurado pero deshabilitado.',
            'public_url' => $config->publicUrl(),
            'federation_url' => $config->federationUrl(),
            'identity_path' => $config->identityPath(),
        ];
    }

    private function federationIdentityCheck(): array
    {
        $config = FederationConfig::fromEnvironment();
        $identity = new NodeIdentityService($config->identityPath());
        return [
            'status' => 'ready',
            'message' => 'Identidad FederationCloud legible y criptográficamente válida.',
            'node_id' => $identity->nodeId(),
            'node_name' => $identity->nodeName() ?? '',
        ];
    }

    private function federationSchemaCheck(): array
    {
        $schemaResult = $this->db->query('SELECT DATABASE() AS db_name');
        $schemaRow = $schemaResult ? ($schemaResult->fetch_assoc() ?: []) : [];
        if ($schemaResult) {
            $schemaResult->free();
        }
        $schema = trim((string)($schemaRow['db_name'] ?? ''));
        if ($schema === '') {
            return ['status' => 'fail', 'message' => 'No se pudo resolver el esquema activo.'];
        }

        $quoted = array_map(
            fn(string $table): string => "'" . $this->db->real_escape_string($table) . "'",
            self::REQUIRED_FEDERATION_TABLES
        );
        $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '"
            . $this->db->real_escape_string($schema)
            . "' AND TABLE_NAME IN (" . implode(',', $quoted) . ')';
        $result = $this->db->query($sql);
        if (!$result) {
            return ['status' => 'fail', 'message' => 'No se pudo consultar information_schema para FederationCloud.'];
        }

        $found = [];
        while ($row = $result->fetch_assoc()) {
            $found[] = (string)$row['TABLE_NAME'];
        }
        $result->free();

        $missing = array_values(array_diff(self::REQUIRED_FEDERATION_TABLES, $found));
        sort($missing);

        return [
            'status' => $missing === [] ? 'ready' : 'fail',
            'message' => $missing === []
                ? 'Esquema FederationCloud esencial presente.'
                : 'Faltan tablas FederationCloud esenciales; ejecutar la migración canónica antes de probar.',
            'required' => count(self::REQUIRED_FEDERATION_TABLES),
            'present' => count($found),
            'missing' => $missing,
        ];
    }

    private function runtimeFilesCheck(): array
    {
        $missing = [];
        foreach (self::REQUIRED_RUNTIME_FILES as $relative) {
            if (!is_file($this->projectRoot . '/' . $relative)) {
                $missing[] = $relative;
            }
        }

        return [
            'status' => $missing === [] ? 'ready' : 'fail',
            'message' => $missing === [] ? 'Endpoints y servicios críticos presentes en el checkout.' : 'Faltan archivos críticos del runtime.',
            'missing' => $missing,
        ];
    }

    private function nodeCapacityCheck(): array
    {
        $snapshot = (new NodeCapabilityService())->snapshot($this->projectRoot . '/drive');
        $diskFree = (int)($snapshot['disk_free_bytes'] ?? 0);
        $memoryAvailable = (int)($snapshot['memory_available_bytes'] ?? 0);
        $status = 'ready';
        $reasons = [];

        if ($diskFree > 0 && $diskFree < 1024 * 1024 * 1024) {
            $status = 'warning';
            $reasons[] = 'menos de 1 GiB de disco libre';
        }
        if ($memoryAvailable > 0 && $memoryAvailable < 128 * 1024 * 1024) {
            $status = 'warning';
            $reasons[] = 'menos de 128 MiB de RAM disponible';
        }

        return [
            'status' => $status,
            'message' => $reasons === [] ? 'Capacidad local sin alertas básicas.' : implode('; ', $reasons) . '.',
            'node_role' => (string)($snapshot['role'] ?? ''),
            'disk_free_bytes' => $diskFree,
            'memory_available_bytes' => $memoryAvailable,
            'vcpu' => (int)($snapshot['vcpu'] ?? 0),
            'docker_installed' => (bool)($snapshot['docker_installed'] ?? false),
            'ffmpeg_available' => (bool)($snapshot['ffmpeg_available'] ?? false),
        ];
    }
}
