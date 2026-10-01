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

    /** Units are fixed here: no request value can become a systemctl argument. */
    public const SERVICE_WHITELIST = [
        'nginx.service' => 'Nginx',
        'php-fpm-drive.service' => 'PHP-FPM Drive',
        'arcadecloud-federation-sync.service' => 'Federation Sync',
        'arcadecloud-federation-sync.timer' => 'Federation Sync timer',
        'arcadecloud-federation-drop-cleanup.service' => 'Federation Drop cleanup',
        'arcadecloud-federation-drop-cleanup.timer' => 'Federation Drop cleanup timer',
        'arcadecloud-federation-https.service' => 'Federation HTTPS',
        'arcadecloud-federation-https.timer' => 'Federation HTTPS timer',
        'arcadecloud-polly-reconcile.service' => 'Polly reconcile',
        'arcadecloud-polly-reconcile.timer' => 'Polly reconcile timer',
        'arcadecloud-transcribe-reconcile.service' => 'Transcribe reconcile',
        'arcadecloud-transcribe-reconcile.timer' => 'Transcribe reconcile timer',
        'arcadecloud-media-worker.service' => 'Media Worker',
        'arcadecloud-media-node-bootstrap.service' => 'Media node bootstrap',
        'arcadecloud-workstation.service' => 'Workstation',
        'docker.service' => 'Docker',
    ];

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

        return array_merge($this->legacy($capability, $resources), [
            'kind' => 'local',
            'generated_at' => gmdate('c'),
            'health' => $health,
            'thresholds' => self::THRESHOLDS,
            'resources' => $resources,
            'services' => $services,
            'programs' => $this->cachedPrograms(),
            'php' => $this->php($services),
            'nginx' => $this->nginx($services),
            'database' => $database,
            'users' => $this->users(),
            'federation' => $this->federation(),
            'activity' => $activity,
            'network' => [
                'hostname' => (string)($capability['hostname'] ?? ''),
                'private_ip' => $this->privateIp(),
                'public_ip' => '',
                'mysql' => ($database['available'] ?? false) ? 'available' : 'unavailable',
                's3' => 'not_probed',
            ],
        ]);
    }

    public function fastDrive(): array
    {
        $result = [
            'kind' => 'fastdrive', 'hostname' => 'fastdrive.esforzados.com',
            'configured' => false, 'state' => 'unconfigured', 'health' => ['state' => 'neutral', 'label' => 'No configurado', 'reasons' => []],
            'internal_status' => 'unavailable', 'internal_message' => 'Estado interno no consultable: no hay un canal remoto de diagnóstico configurado.',
        ];
        if (!$this->app->session()->isSuperAdmin()) {
            $result['internal_message'] = 'El estado AWS de FastDrive sólo está disponible para superadmin.';
            return $result;
        }
        try {
            $status = (new \ArcadeCloud\Drive\Admin\FastDriveControlService($this->app))->status();
            $state = (string)($status['state'] ?? 'unknown');
            $result = array_merge($result, $status, ['configured' => true]);
            $result['health'] = in_array($state, ['stopped', 'stopping'], true)
                ? ['state' => 'neutral', 'label' => $state === 'stopped' ? 'Apagado' : 'Apagándose', 'reasons' => []]
                : ($state === 'pending'
                    ? ['state' => 'warning', 'label' => 'Iniciando', 'reasons' => ['EC2 pending']]
                    : ($state === 'running'
                        ? ['state' => 'ok', 'label' => 'Running', 'reasons' => []]
                        : ['state' => 'warning', 'label' => 'No disponible', 'reasons' => ['Estado EC2: ' . $state]]));
            if ($state !== 'running') {
                $result['internal_message'] = 'Servicios internos no consultables porque el nodo no está running.';
            }
            try {
                $idle = (new MediaWorkerNodeService($this->app->db()))->idleStatus();
                $result['autoshutdown'] = $this->normalizeIdle($idle, $activity = $this->activity());
            } catch (Throwable) {
                $result['autoshutdown'] = ['available' => false];
            }
        } catch (Throwable $e) {
            $result['state'] = 'unknown';
            $result['health'] = ['state' => 'warning', 'label' => 'No disponible', 'reasons' => ['AWS no respondió']];
        }
        return $result;
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
        foreach (self::SERVICE_WHITELIST as $unit => $label) {
            $show = $this->command(['systemctl', 'show', $unit, '--no-pager', '--property=LoadState,ActiveState,UnitFileState,MainPID,ActiveEnterTimestamp,Result,LastTriggerUSec,NextElapseUSecRealtime'], 1.2);
            $values = [];
            foreach (explode("\n", $show['output']) as $line) {
                if (str_contains($line, '=')) [$key, $value] = explode('=', $line, 2); else continue;
                $values[$key] = trim($value);
            }
            if (($values['LoadState'] ?? 'not-found') === 'not-found') continue;
            $units[] = ['unit' => $unit, 'name' => $label, 'installed' => true,
                'active' => (string)($values['ActiveState'] ?? 'unknown'), 'enabled' => (string)($values['UnitFileState'] ?? 'unknown'),
                'pid' => (int)($values['MainPID'] ?? 0), 'since' => (string)($values['ActiveEnterTimestamp'] ?? ''),
                'result' => (string)($values['Result'] ?? ''), 'last_trigger' => (string)($values['LastTriggerUSec'] ?? ''),
                'next_trigger' => (string)($values['NextElapseUSecRealtime'] ?? '')];
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
            $status = $this->mysqlPairs($db, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Uptime','Slow_queries')");
            $vars = $this->mysqlPairs($db, "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections')");
            if (!isset($status['Threads_connected'], $vars['max_connections'])) return $base;
            $connected = (int)$status['Threads_connected']; $max = max(1, (int)$vars['max_connections']);
            return array_merge($base, ['advanced_available' => true, 'threads_connected' => $connected,
                'threads_running' => (int)($status['Threads_running'] ?? 0), 'max_connections' => $max,
                'connections_used_percent' => round($connected * 100 / $max, 1), 'uptime_seconds' => (int)($status['Uptime'] ?? 0),
                'slow_queries' => (int)($status['Slow_queries'] ?? 0)]);
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
        $out = ['enabled' => false, 'known' => null, 'recently_seen' => null, 'available' => null, 'unavailable' => null];
        try {
            $config = FederationConfig::fromEnvironment();
            $identity = new NodeIdentityService($config->identityPath());
            $out = array_merge($out, ['enabled' => $config->enabled(), 'node_id' => $identity->nodeId(),
                'node_name' => $identity->nodeName(),
                'role' => strtolower(trim((string)(getenv('ARCADECLOUD_NODE_ROLE') ?: 'web'))),
                'seed_configured' => trim((string)(getenv('ARCADECLOUD_FEDERATION_SEED_URL') ?: '')) !== '']);
            $result = $this->app->db()->query("SELECT COUNT(*) known, SUM(LastSeen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) recent, SUM(Status='active' AND LastSeen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) available, MAX(LastSeen) last_sync FROM FederationNodes");
            $row = $result?->fetch_assoc(); $result?->free();
            if (is_array($row)) { $out['known']=(int)$row['known']; $out['recently_seen']=(int)$row['recent']; $out['available']=(int)$row['available']; $out['unavailable']=max(0,$out['known']-$out['available']); $out['last_sync_at']=$row['last_sync']; }
        } catch (Throwable) { $out['message'] = 'Estado de FederationCloud no disponible.'; }
        return $out;
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
        return ['version' => PHP_VERSION, 'service' => $service, 'pool_metrics_available' => false,
            'pool_message' => 'Métricas del pool no disponibles sin un status FPM seguro.',
            'settings' => ['memory_limit' => ini_get('memory_limit'), 'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'), 'max_execution_time' => ini_get('max_execution_time')]];
    }

    private function nginx(array $services): array
    {
        $test = $this->executable('nginx') ? $this->command(['nginx', '-t'], 2.0) : ['ok' => false, 'output' => ''];
        return ['service' => $this->service($services, 'nginx.service'), 'config_ok' => (bool)$test['ok'],
            'config_message' => $this->sanitizeLine($test['output']), 'stub_status_available' => false];
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
        foreach ($services as $s) if (($s['active'] ?? '') === 'failed') $critical[]=$s['name'].' failed';
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
        $output.=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);$code=$exitCode??$closed;return ['ok'=>!$timed&&$code===0,'output'=>substr($output,0,2048)];
    }
    private function sanitizeLine(string $value): string { $line=trim((string)(preg_split('/\R/',$value)[0]??'')); return substr(preg_replace('/[\x00-\x1F\x7F]/','',$line)??'',0,180); }
}
