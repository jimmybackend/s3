<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\System;

use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use Throwable;

final class LocalContainerCapabilityService
{
    private const KEYS = ['libreoffice','Xtigervnc','git','python3','aws','ffmpeg','ffprobe','novnc_proxy'];

    public function programs(array $host, array $services): array
    {
        $cacheKey = 'arcadecloud.node.local-container-programs.v1';
        $found = false;
        $containers = function_exists('apcu_fetch') ? apcu_fetch($cacheKey, $found) : null;
        if (!$found || !is_array($containers)) {
            try { $containers = (new PrivilegedServerHelper())->localContainerPrograms(); }
            catch (Throwable) { $containers = []; }
            if (function_exists('apcu_store')) apcu_store($cacheKey, $containers, 30);
        }
        return self::merge($host, $containers, $services);
    }

    public static function merge(array $host, array $containers, array $services): array
    {
        $serviceStopped = false;
        foreach ($services as $service) {
            if (($service['unit'] ?? '') === 'arcadecloud-workstation.service'
                && in_array($service['active'] ?? '', ['inactive','failed'], true)) $serviceStopped = true;
        }
        foreach ($host as &$program) {
            $key = (string)($program['key'] ?? '');
            $program['host_installed'] = (bool)($program['installed'] ?? false);
            $program['container_installed'] = false;
            $program['container_available'] = false;
            $program['available'] = $program['host_installed'];
            $program['state'] = $program['host_installed'] ? 'host' : 'unavailable';
            if (!in_array($key, self::KEYS, true)) continue;
            $container = $containers[$key] ?? [];
            $state = (string)($container['state'] ?? 'unknown');
            if (($container['container'] ?? '') !== 'arcadecloud-workstation') $state = 'unknown';
            if (!in_array($state, ['container','container_stopped','unavailable','unknown'], true)) $state = 'unknown';
            if ($state === 'unavailable' && $serviceStopped) $state = 'service_stopped';
            $program['container_state'] = $state;
            $program['container_installed'] = in_array($state, ['container','container_stopped'], true) && ($container['installed'] ?? false) === true;
            $program['container_available'] = $state === 'container' && $program['container_installed'] && ($container['available'] ?? false) === true;
            if ($program['container_installed']) $program['container'] = 'arcadecloud-workstation';
            // Keep installed as host-only for older consumers; available is explicit.
            $program['available'] = $program['host_installed'] || $program['container_available'];
            if (!$program['host_installed']) $program['state'] = $state;
        }
        unset($program);
        return $host;
    }
}
