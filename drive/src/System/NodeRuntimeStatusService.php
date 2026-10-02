<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\System;

use ArcadeCloud\Drive\Admin\ServerTaskActivityProbe;
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\NodeIdentityService;
use ArcadeCloud\Drive\Media\MediaWorkerNodeService;
use ArcadeCloud\Drive\View\FileViewHelper;
use mysqli;
use Throwable;

/** Builds the read-only, best-effort diagnostics shown by “Mi nodo”. */
final class NodeRuntimeStatusService
{
    public const THRESHOLDS = [
        'disk_warning_percent' => 80,
        'disk_critical_percent' => 90,
        'memory_available_warning_percent' => 15,
        'memory_available_critical_percent' => 8,
        'mysql_connections_warning_percent' => 80,
        'mysql_connections_critical_percent' => 95,
    ];

    /** Backwards-compatible read-only map; actions use NodeServiceCatalog IDs. */
    public const SERVICE_WHITELIST = NodeServiceCatalog::COMPONENTS;

    private const PROGRAMS = [
        'php' => ['PHP', ['php', '-v']],
        'nginx' => ['Nginx', ['nginx', '-v']],
        'mysql' => ['MySQL client', ['mysql', '--version']],
        'ffmpeg' => ['FFmpeg', ['ffmpeg', '-version']],
        'ffprobe' => ['FFprobe', ['ffprobe', '-version']],
        'docker' => ['Docker', ['docker', '--version']],
        'libreoffice' => ['LibreOffice', ['libreoffice', '--version']],
        'Xtigervnc' => ['TigerVNC', ['Xtigervnc', '-version']],
        'novnc_proxy' => ['noVNC', ['novnc_proxy', '--help']],
        'guacd' => ['Guacamole (guacd)', ['guacd', '-v']],
        'git' => ['Git', ['git', '--version']],
        'aws' => ['AWS CLI', ['aws', '--version']],
        'python3' => ['Python', ['python3', '--version']],
        'certbot' => ['Certbot', ['certbot', '--version']],
    ];

    public function __construct(private DriveApplication $app) {}

    public function local(string $diskPath): array
    {
        $capability = (new NodeCapabilityService())->snapshot($diskPath);
        $resources = $this->resources($capability, $diskPath);
        $services = $this->services();
        $database = $this->database();
        $activity = $this->activity();
        $health = $this->health($resources, $services, $database);
        $identity = $this->localIdentity($capability);
        $autoshutdown = $this->localAutoShutdown($capability, $activity);

        return array_merge($this->legacy($capability, $resources), [
            'kind' => 'local',
            'scope' => 'local',
            'identity' => $identity,
            'generated_at' => gmdate('c'),
            'health' => $health,
            'thresholds' => self::THRESHOLDS,
            'resources' => $resources,
            'services' => $services,
            'processes' => $this->processMemory(),
            'programs' => (new LocalContainerCapabilityService())->programs($this->cachedPrograms(), $services),
            'php' => $this->php($services),
            'nginx' => $this->nginx($services),
            'database' => $database,
            'users' => $this->users(),
            'federation' => $this->federation(),
            'activity' => $activity,
            'autoshutdown' => $autoshutdown,
            'network' => [
                'hostname' => (string)($capability['hostname'] ?? ''),
                'private_ip' => (string)($capability['private_ip'] ?? '') ?: $this->privateIp(),
                'public_ip' => (string)($capability['public_ip'] ?? ''),
                'mysql' => ($database['available'] ?? false) ? 'available' : 'unavailable',
                's3' => 'not_probed',
            ],
        ]);
    }

    private function localIdentity(array $capability): array
    {
        $role = strtolower(trim((string)($capability['role'] ?? (getenv('ARCADECLOUD_NODE_ROLE') ?: 'web'))));
        $publicUrl = $this->safePublicUrl(trim((string)(getenv('ARCADECLOUD_PUBLIC_URL') ?: '')));
        $nodeId = '';
        $nodeName = '';

        try {
            $config = FederationConfig::fromEnvironment();
            $identity = new NodeIdentityService($config->identityPath());
            $nodeId = $identity->nodeId();
            $nodeName = $identity->nodeName();
        } catch (Throwable) {
            // Federation identity is optional for diagnostics; never substitute a peer.
        }

        $publicHost = '';
        if ($publicUrl !== '') {
            $parsedHost = parse_url($publicUrl, PHP_URL_HOST);
            $publicHost = is_string($parsedHost) ? strtolower(trim($parsedHost)) : '';
        }

        $normalizedNodeName = strtolower(trim($nodeName));
        $operatingKey = 'local';
        $operatingLabel = 'Nodo local';
        if (
            in_array($role, ['media-worker', 'combined'], true)
            || $normalizedNodeName === 'fastdrive'
            || $publicHost === 'fastdrive.esforzados.com'
        ) {
            $operatingKey = 'fastdrive';
            $operatingLabel = 'FastDrive';
        } elseif (
            $role === 'web'
            || $normalizedNodeName === 'esforzados'
            || $publicHost === 'drive.esforzados.com'
        ) {
            $operatingKey = 'drive';
            $operatingLabel = 'Drive principal';
        }

        return [
            'node_id' => $nodeId,
            'node_name' => $nodeName,
            'display_name' => $nodeName !== '' ? $nodeName : ($publicHost !== '' ? $publicHost : (string)($capability['hostname'] ?? 'ArcadeCloud')),
            'operating_key' => $operatingKey,
            'operating_label' => $operatingLabel,
            'public_url' => $publicUrl,
            'hostname' => (string)($capability['hostname'] ?? ''),
            'role' => $role,
            'instance_id' => (string)($capability['instance_id'] ?? ''),
            'instance_type' => (string)($capability['instance_type'] ?? ''),
        ];
    }

    private function localAutoShutdown(array $capability, array $activity): array
    {
        $role = strtolower(trim((string)($capability['role'] ?? 'web')));
        if (!in_array($role, ['media-worker', 'combined'], true)) {
            return ['available' => false, 'enabled' => false, 'scope' => 'local'];
        }

        try {
            $idle = (new MediaWorkerNodeService($this->app->db()))->idleStatus();
            $localInstanceId = trim((string)($capability['instance_id'] ?? ''));
            $targetInstanceId = trim((string)($idle['instance_id'] ?? ''));
            if ($localInstanceId !== '' && $targetInstanceId !== '' && !hash_equals($localInstanceId, $targetInstanceId)) {
                return ['available' => false, 'enabled' => false, 'scope' => 'local'];
            }
            return ['scope' => 'local'] + $this->normalizeIdle($idle, $activity);
        } catch (Throwable) {
            return ['available' => false, 'enabled' => false, 'scope' => 'local'];
        }
    }

    private function resources(array $c, string $path): array
    {
        $mt = max(0, (int)($c['memory_total_bytes'] ?? 0));
        $ma = max(0, (int)($c['memory_available_bytes'] ?? 0));
        $st = max(0, (int)($c['swap_total_bytes'] ?? 0));
        $sf = max(0, (int)($c['swap_free_bytes'] ?? 0));
        $dt = max(0, (int)($c['disk_total_bytes'] ?? 0));
        $df = max(0, (int)($c['disk_free_bytes'] ?? 0));
        $stat = function_exists('statvfs') ? @statvfs($path) : false;
        $uptime = @file_get_contents('/proc/uptime');
        $uptimeSeconds = is_string($uptime) ? (int)(float)explode(' ', trim($uptime))[0] : 0;
        return [
            'vcpu' => (int)($c['vcpu'] ?? 0), 'load_average' => $c['load_average'] ?? [0, 0, 0],
            'load_per_vcpu' => round(((float)($c['load_average'][0] ?? 0)) / max(1, (int)($c['vcpu'] ?? 1)), 2),
            'uptime_seconds' => $uptimeSeconds,
            'memory' => $this->usage($mt, $mt - $ma, $ma),
            'swap' => $this->usage($st, $st - $sf, $sf),
            'disk' => array_merge($this->usage($dt, $dt - $df, $df), [
                'mount_point' => realpath($path) ?: $path,
                'inode_total' => is_array($stat) ? (int)($stat['files'] ?? 0) : null,
                'inode_free' => is_array($stat) ? (int)($stat['ffree'] ?? 0) : null,
            ]),
        ];
    }

    private function usage(int $total, int $used, int $available): array
    {
        return ['total_bytes' => $total, 'used_bytes' => max(0, $used), 'available_bytes' => $available,
            'total' => FileViewHelper::formatBytes($total), 'used' => FileViewHelper::formatBytes(max(0, $used)),
            'available' => FileViewHelper::formatBytes($available), 'used_percent' => $total ? round(max(0, $used) * 100 / $total, 1) : 0.0];
    }

    private function services(): array
    {
        if (!$this->executable('systemctl')) return [];
        $units = [];
        foreach (NodeServiceCatalog::COMPONENTS as $componentId => $definition) {
            if (in_array($componentId, ['mysql', 'mariadb'], true) && $this->databaseType() !== 'local') continue;
            $unit = (string)$definition['service_name'];
            $show = $this->command(['systemctl', 'show', $unit, '--no-pager', '--property=LoadState,ActiveState,SubState,UnitFileState,MainPID,ActiveEnterTimestamp,InactiveEnterTimestamp,Result,ExecMainCode,ExecMainStatus,LastTriggerUSec,NextElapseUSecRealtime'], 1.2);
            $values = [];
            foreach (explode("\n", $show['output']) as $line) {
                if (str_contains($line, '=')) [$key, $value] = explode('=', $line, 2); else continue;
                $values[$key] = trim($value);
            }
            if (($values['LoadState'] ?? 'not-found') === 'not-found') continue;
            $active = (string)($values['ActiveState'] ?? 'unknown');
            $enabled = (string)($values['UnitFileState'] ?? 'unknown');
            $type = (string)$definition['type'];
            $intent = in_array($enabled, ['disabled', 'masked'], true) && !($definition['critical'] ?? true) ? 'disabled_by_admin' : null;
            $timer = isset($definition['timer_name']) ? $this->timerSnapshot((string)$definition['timer_name']) : null;
            $healthyIdle = in_array($type, ['oneshot', 'static-helper'], true) && $active === 'inactive' && (($values['Result'] ?? 'success') === 'success');
            $diagnostic = $active === 'failed' ? $this->failureDiagnostic($unit) : [];
            $units[] = ['id' => $componentId, 'unit' => $unit, 'name' => (string)$definition['friendly_name'], 'installed' => true,
                'type' => $type, 'category' => (string)$definition['category'], 'critical' => (bool)$definition['critical'],
                'allowed_actions' => array_values((array)$definition['allowed_actions']), 'intent' => $intent, 'healthy_idle' => $healthyIdle,
                'active' => (string)($values['ActiveState'] ?? 'unknown'), 'enabled' => (string)($values['UnitFileState'] ?? 'unknown'),
                'substate' => (string)($values['SubState'] ?? ''),
                'pid' => (int)($values['MainPID'] ?? 0), 'since' => (string)($values['ActiveEnterTimestamp'] ?? ''),
                'result' => (string)($values['Result'] ?? ''), 'last_trigger' => (string)($values['LastTriggerUSec'] ?? ''),
                'last_run' => (string)($values['InactiveEnterTimestamp'] ?? $values['ActiveEnterTimestamp'] ?? ''),
                'exit_code' => (int)($values['ExecMainStatus'] ?? 0), 'exit_kind' => (string)($values['ExecMainCode'] ?? ''),
                'next_trigger' => (string)($values['NextElapseUSecRealtime'] ?? ''), 'timer' => $timer, 'diagnostic' => $diagnostic];
        }
        return $units;
    }

    private function database(): array
    {
        $db = $this->app->db();
        $started = hrtime(true);
        $available = @$db->ping();
        $base = ['type' => $this->databaseType(), 'host' => $this->safeDbHost(), 'available' => $available,
            'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 1), 'advanced_available' => false];
        if (!$available) return $base;
        try {
            $status = $this->mysqlPairs($db, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Connections','Aborted_connects','Uptime','Slow_queries')");
            $vars = $this->mysqlPairs($db, "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections')");
            if (!isset($status['Threads_connected'], $vars['max_connections'])) return $base;
            $connected = (int)$status['Threads_connected']; $max = max(1, (int)$vars['max_connections']);
            return array_merge($base, ['advanced_available' => true, 'threads_connected' => $connected,
                'threads_running' => (int)($status['Threads_running'] ?? 0), 'max_connections' => $max,
                'connections_used_percent' => round($connected * 100 / $max, 1), 'uptime_seconds' => (int)($status['Uptime'] ?? 0),
                'connections' => (int)($status['Connections'] ?? 0), 'aborted_connects' => (int)($status['Aborted_connects'] ?? 0),
                'slow_queries' => (int)($status['Slow_queries'] ?? 0), 'database_size_bytes' => $this->cachedDatabaseSize($db)]);
        } catch (Throwable) { return $base; }
    }

    private function mysqlPairs(mysqli $db, string $sql): array
    {
        $result = $db->query($sql); if (!$result) return [];
        $pairs = []; while ($row = $result->fetch_row()) $pairs[(string)$row[0]] = (string)$row[1];
        $result->free(); return $pairs;
    }

    private function users(): array
    {
        try {
            $result = $this->app->db()->query('SELECT COUNT(*) FROM Users');
            $row = $result?->fetch_row(); $result?->free();
            return ['registered' => is_array($row) ? (int)$row[0] : null, 'online_available' => false,
                'message' => 'Sesiones en línea no disponibles actualmente; las sesiones PHP no registran presencia global fiable.'];
        } catch (Throwable) { return ['registered' => null, 'online_available' => false, 'message' => 'Sesiones en línea no disponibles actualmente.']; }
    }

    private function federation(): array
    {
        $out = [
            'enabled' => false,
            'available' => false,
            'scope' => 'local',
            'node_id' => '',
            'node_name' => '',
            'role' => strtolower(trim((string)(getenv('ARCADECLOUD_NODE_ROLE') ?: 'web'))),
            'seed_configured' => false,
        ];
        try {
            $config = FederationConfig::fromEnvironment();
            $identity = new NodeIdentityService($config->identityPath());
            return array_merge($out, [
                'enabled' => $config->enabled(),
                'available' => true,
                'node_id' => $identity->nodeId(),
                'node_name' => $identity->nodeName(),
                'seed_configured' => trim((string)(getenv('ARCADECLOUD_FEDERATION_SEED_URL') ?: '')) !== '',
            ]);
        } catch (Throwable) {
            $out['message'] = 'Identidad FederationCloud local no disponible.';
            return $out;
        }
    }

    private function activity(): array
    {
        try { $summary = (new ServerTaskActivityProbe($this->app))->summary(); }
        catch (Throwable) { $summary = ['active' => null, 'sources' => []]; }
        return $summary;
    }

    private function php(array $services): array
    {
        $service = $this->service($services, 'php-fpm-drive.service');
        $workers = $this->processStats('php-fpm');
        return ['version' => PHP_VERSION, 'service' => $service, 'workers' => $workers['count'], 'memory_bytes' => $workers['memory_bytes'], 'pool_metrics_available' => false,
            'pool_message' => 'Métricas del pool no disponibles sin un status FPM seguro.',
            'settings' => ['memory_limit' => ini_get('memory_limit'), 'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'), 'max_execution_time' => ini_get('max_execution_time')]];
    }

    private function nginx(array $services): array
    {
        $installed = $this->executable('nginx');
        $test = $installed ? $this->command(['nginx', '-t'], 2.0) : ['ok' => false, 'output' => 'El comando nginx no está disponible en PATH.', 'code' => 127];
        $versionProbe = $installed ? $this->command(['nginx', '-v'], 1.0) : ['output' => ''];
        $message = $this->sanitizeLine($test['output']);
        $permission = !$test['ok'] && (str_contains(strtolower($message), 'permission denied') || str_contains(strtolower($message), 'operation not permitted'));
        $stats = $this->processStats('nginx');
        return ['service' => $this->service($services, 'nginx.service'), 'version' => $this->sanitizeLine($versionProbe['output']), 'config_status' => $test['ok'] ? 'ok' : (!$installed ? 'command_unavailable' : ($permission ? 'permission_denied' : 'error')),
            'config_ok' => (bool)$test['ok'], 'config_message' => $permission ? 'No disponible por permisos' : $message,
            'memory_bytes' => $stats['memory_bytes'], 'processes' => $stats['count'], 'stub_status_available' => false];
    }

    private function cachedPrograms(): array
    {
        $key = 'arcadecloud.node.programs.v1';
        if (function_exists('apcu_fetch')) {
            $success = false;
            $cached = apcu_fetch($key, $success);
            if ($success && is_array($cached)) return $cached;
        }
        $programs = [];
        foreach (self::PROGRAMS as $binary => [$name, $command]) {
            $installed = $this->executable($binary); $output = $installed ? $this->command($command, 1.5)['output'] : '';
            $programs[] = ['key' => $binary, 'name' => $name, 'installed' => $installed, 'version' => $this->sanitizeLine($output)];
        }
        if (function_exists('apcu_store')) @apcu_store($key, $programs, 300);
        return $programs;
    }

    private function health(array $resources, array $services, array $database): array
    {
        $critical=[]; $warning=[]; $disk=(float)$resources['disk']['used_percent'];
        $availablePct = ($resources['memory']['total_bytes'] ?? 0) > 0 ? 100 * $resources['memory']['available_bytes'] / $resources['memory']['total_bytes'] : 100;
        if ($disk >= self::THRESHOLDS['disk_critical_percent']) $critical[]='Disco críticamente lleno'; elseif ($disk >= self::THRESHOLDS['disk_warning_percent']) $warning[]='Disco cerca del límite';
        if ($availablePct <= self::THRESHOLDS['memory_available_critical_percent']) $critical[]='Memoria disponible crítica'; elseif ($availablePct <= self::THRESHOLDS['memory_available_warning_percent']) $warning[]='Memoria disponible baja';
        foreach (['nginx.service','php-fpm-drive.service'] as $unit) { $s=$this->service($services,$unit); if ($s && ($s['active']??'') !== 'active') $critical[]=$s['name'].' no está activo'; }
        foreach ($services as $s) if (($s['active'] ?? '') === 'failed') { if ($s['critical'] ?? false) $critical[]=$s['name'].' falló'; else $warning[]=$s['name'].' falló en la última ejecución'; }
        if (!($database['available'] ?? false)) $critical[]='MySQL no disponible';
        $mysql=(float)($database['connections_used_percent']??0); if ($mysql>=95)$critical[]='Conexiones MySQL críticas'; elseif($mysql>=80)$warning[]='Conexiones MySQL cerca del máximo';
        return $critical ? ['state'=>'problem','label'=>'Problema','reasons'=>$critical] : ($warning ? ['state'=>'warning','label'=>'Atención','reasons'=>$warning] : ['state'=>'ok','label'=>'Correcto','reasons'=>[]]);
    }

    private function normalizeIdle(array $idle, array $activity): array
    {
        $timeout=(int)($idle['idle_grace_seconds']??0); $elapsed=(int)($idle['idle_elapsed_seconds']??0);
        $blockers=[]; foreach ((array)($activity['sources']??[]) as $name=>$busy) if($busy)$blockers[]=(string)$name;
        $warning = max(0, (int)($idle['warning_seconds'] ?? 0));
        return ['available'=>(bool)($idle['configured']??false),'enabled'=>(bool)($idle['configured']??false),
            'idle_timeout_seconds'=>$timeout,'idle_seconds'=>$elapsed,'remaining_seconds'=>max(0,$timeout+$warning-$elapsed),
            'last_activity_at'=>isset($idle['idle_since'])&&$idle['idle_since']!==''?gmdate('c',max(0,time()-$elapsed)):null,
            'shutdown_blockers'=>$blockers,'state'=>(string)($idle['state']??'unknown')];
    }

    private function timerSnapshot(string $unit): ?array
    {
        $show = $this->command(['systemctl', 'show', $unit, '--no-pager', '--property=LoadState,ActiveState,UnitFileState,LastTriggerUSec,NextElapseUSecRealtime'], 1.0);
        $values = $this->propertyLines($show['output']);
        if (($values['LoadState'] ?? 'not-found') === 'not-found') return null;
        $next = (string)($values['NextElapseUSecRealtime'] ?? '');
        $timestamp = $next !== '' ? strtotime($next) : false;
        return ['unit' => $unit, 'active' => (string)($values['ActiveState'] ?? 'unknown'), 'enabled' => (string)($values['UnitFileState'] ?? 'unknown'),
            'last_trigger' => (string)($values['LastTriggerUSec'] ?? ''), 'next_trigger' => $next,
            'remaining_seconds' => $timestamp === false ? null : max(0, $timestamp - time())];
    }

    private function failureDiagnostic(string $unit): array
    {
        $journal = $this->command(['journalctl', '-u', $unit, '-n', '5', '--no-pager', '-o', 'cat'], 1.5);
        $lines = [];
        foreach (array_slice(preg_split('/\R/', trim($journal['output'])) ?: [], -5) as $line) {
            $clean = $this->sanitizeDiagnostic($line);
            if ($clean !== '') $lines[] = $clean;
        }
        return ['journal' => $lines, 'available' => $lines !== []];
    }

    private function propertyLines(string $output): array
    {
        $values = [];
        foreach (explode("\n", $output) as $line) if (str_contains($line, '=')) { [$key, $value] = explode('=', $line, 2); $values[$key] = trim($value); }
        return $values;
    }

    private function processMemory(): array
    {
        $definitions = ['php-fpm' => 'PHP-FPM', 'nginx' => 'Nginx', 'mysqld' => 'MySQL', 'mariadbd' => 'MariaDB', 'media_processing' => 'Media Worker', 'docker' => 'Workstation / Docker'];
        $rows = [];
        foreach ($definitions as $needle => $name) {
            $stats = $this->processStats($needle);
            if ($stats['count'] > 0) $rows[] = ['name' => $name, 'memory_bytes' => $stats['memory_bytes'], 'processes' => $stats['count']];
        }
        usort($rows, static fn(array $a, array $b): int => $b['memory_bytes'] <=> $a['memory_bytes']);
        return $rows;
    }

    private function processStats(string $needle): array
    {
        $bytes = 0; $count = 0;
        foreach (glob('/proc/[0-9]*/comm', GLOB_NOSORT) ?: [] as $commPath) {
            $comm = @file_get_contents($commPath);
            if (!is_string($comm) || !str_contains(strtolower($comm), strtolower($needle))) continue;
            $status = @file_get_contents(dirname($commPath) . '/status');
            if (!is_string($status)) continue;
            if (preg_match('/^VmRSS:\s+(\d+)\s+kB/im', $status, $match)) $bytes += (int)$match[1] * 1024;
            $count++;
        }
        return ['memory_bytes' => $bytes, 'count' => $count];
    }

    private function cachedDatabaseSize(mysqli $db): ?int
    {
        $key = 'arcadecloud.node.database-size.v1';
        if (function_exists('apcu_fetch')) { $ok = false; $value = apcu_fetch($key, $ok); if ($ok && is_int($value)) return $value; }
        $name = (string)(getenv('DB_NAME') ?: ''); if ($name === '') return null;
        $stmt = $db->prepare('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema=?');
        if (!$stmt) return null; $stmt->bind_param('s', $name); if (!$stmt->execute()) { $stmt->close(); return null; }
        $stmt->bind_result($size); $stmt->fetch(); $stmt->close(); $bytes = (int)$size;
        if (function_exists('apcu_store')) @apcu_store($key, $bytes, 300);
        return $bytes;
    }

    private function safePublicUrl(string $url): string
    {
        $parts = parse_url($url); if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) return '';
        $host = (string)($parts['host'] ?? ''); if ($host === '') return '';
        return strtolower((string)$parts['scheme']) . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '') . (string)($parts['path'] ?? '');
    }

    private function legacy(array $c, array $r): array
    {
        return ['hostname'=>(string)($c['hostname']??''),'role'=>(string)($c['role']??''),'instance_id'=>(string)($c['instance_id']??''),
            'instance_type'=>(string)($c['instance_type']??''),'availability_zone'=>(string)($c['availability_zone']??''),'vcpu'=>(int)($c['vcpu']??0),
            'memory_total_bytes'=>$r['memory']['total_bytes'],'memory_total'=>$r['memory']['total'],'memory_available_bytes'=>$r['memory']['available_bytes'],
            'memory_available'=>$r['memory']['available'],'swap_total'=>$r['swap']['total'],'swap_free'=>$r['swap']['available'],
            'disk_total'=>$r['disk']['total'],'disk_used'=>$r['disk']['used'],'disk_used_percent'=>$r['disk']['used_percent'],'disk_free'=>$r['disk']['available'],
            'load_average'=>$r['load_average'],'ffmpeg_available'=>(bool)($c['ffmpeg_available']??false),'ffprobe_available'=>(bool)($c['ffprobe_available']??false),
            'docker_installed'=>(bool)($c['docker_installed']??false),'gpu_present'=>(bool)($c['gpu_present']??false)];
    }

    private function service(array $services,string $unit): ?array { foreach($services as $s)if(($s['unit']??'')===$unit)return $s; return null; }
    private function safeDbHost(): string { $host=trim((string)(getenv('DB_HOST')?:'')); return preg_replace('/[^A-Za-z0-9._:-]/','',$host)??''; }
    private function databaseType(): string { $host=strtolower($this->safeDbHost()); return in_array($host,['localhost','127.0.0.1','::1'],true)||str_starts_with($host,'/')?'local':'remote'; }
    private function privateIp(): string { $ip=gethostbyname((string)(gethostname()?:'')); return filter_var($ip,FILTER_VALIDATE_IP)?$ip:''; }
    private function executable(string $name): bool { if($name===''||str_contains($name,'/'))return false; foreach(explode(PATH_SEPARATOR,(string)(getenv('PATH')?:'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'))as $dir)if(is_executable(rtrim($dir,'/').'/'.$name))return true; return false; }
    private function command(array $argv,float $timeout): array
    {
        if (!$argv || !$this->executable((string)$argv[0])) return ['ok'=>false,'output'=>''];
        $pipes=[]; $process=@proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['LC_ALL'=>'C','PATH'=>(string)(getenv('PATH')?:'/usr/bin:/bin')]);
        if(!is_resource($process))return ['ok'=>false,'output'=>'']; fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $output='';$start=microtime(true);$timed=false;$exitCode=null; do{$output.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);$status=proc_get_status($process);if(!$status['running']){$exitCode=(int)$status['exitcode'];break;}if(microtime(true)-$start>$timeout){$timed=true;proc_terminate($process);break;}usleep(10000);}while(true);
        $output.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);$code=$exitCode??$closed;return ['ok'=>!$timed&&$code===0,'output'=>substr($output,0,2048),'code'=>$code,'timed_out'=>$timed];
    }
    private function sanitizeLine(string $value): string { $line=trim((string)(preg_split('/\R/',$value)[0]??'')); return substr(preg_replace('/[\x00-\x1F\x7F]/','',$line)??'',0,180); }
    private function sanitizeDiagnostic(string $value): string
    {
        $value = preg_replace('/\b(password|passwd|token|secret|authorization|credential|api[_-]?key)\s*[:=]\s*\S+/i', '$1=[REDACTADO]', $value) ?? '';
        $value = preg_replace('/\b(AKIA|ASIA)[A-Z0-9]{12,}\b/', '[REDACTADO]', $value) ?? '';
        $value = preg_replace('/\b[a-z][a-z0-9+.-]*:\/\/[^\s@]+@/i', 'https://[REDACTADO]@', $value) ?? '';
        return substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? ''), 0, 240);
    }
}
