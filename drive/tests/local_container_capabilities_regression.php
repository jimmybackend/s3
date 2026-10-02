<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bin/arcadecloud-drive-admin-helper.php';
require_once dirname(__DIR__) . '/src/System/LocalContainerCapabilityService.php';

use ArcadeCloud\Drive\System\LocalContainerCapabilityService;

function capabilityCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
$helper = new ArcadeCloudDriveAdminHelper();
$mode = 'running'; $requests = [];
$reader = static function (string $method, string $path) use (&$mode, &$requests): array {
    $requests[] = [$method, $path];
    capabilityCheck(in_array($method, ['GET','HEAD'], true) && str_starts_with($path, '/containers/arcadecloud-workstation/'), 'probe is read-only and targets the fixed local container');
    if ($method === 'GET') {
        if ($mode === 'absent') return ['status' => 404];
        if ($mode === 'denied') return ['status' => 403, 'body' => 'sensitive-error'];
        if ($mode === 'malformed') return ['status' => 200, 'body' => '{}'];
        return ['status' => 200, 'body' => json_encode(['State' => ['Running' => $mode !== 'stopped', 'Paused' => $mode === 'paused'], 'Config' => ['Env' => ['DO_NOT_EXPOSE=fixture-private']]])];
    }
    if (str_contains($path, 'ffmpeg') || str_contains($path, 'ffprobe')) return ['status' => 404];
    if ($mode === 'stat_error') return ['status' => 500];
    if ($mode === 'bad_stat') return ['status' => 200, 'stat' => 'not-base64-json'];
    $permissions = $mode === 'not_executable' ? 0644 : ($mode === 'directory' ? 0x80000000 | 0755 : 0755);
    return ['status' => 200, 'stat' => base64_encode(json_encode(['mode' => $permissions]))];
};
$host = [['key' => 'libreoffice', 'name' => 'LibreOffice', 'installed' => false], ['key' => 'php', 'name' => 'PHP', 'installed' => true]];
foreach (['running' => 'container', 'stopped' => 'container_stopped', 'paused' => 'container_stopped', 'absent' => 'unavailable', 'denied' => 'unknown', 'malformed' => 'unknown', 'stat_error' => 'unknown', 'bad_stat' => 'unknown', 'not_executable' => 'unknown', 'directory' => 'unknown'] as $mode => $expected) {
    $requests = [];
    $result = $helper->localContainerPrograms($reader);
    $programs = LocalContainerCapabilityService::merge($host, $result['programs'], []);
    capabilityCheck($programs[0]['state'] === $expected, "$mode produces the correct local program state");
    capabilityCheck($programs[0]['installed'] === false && !$programs[0]['host_installed'], 'container never becomes host installation');
    capabilityCheck($programs[0]['available'] === ($mode === 'running'), 'only a verified running container is available');
    capabilityCheck($programs[1]['state'] === 'host', 'existing host program remains installed');
    capabilityCheck(!str_contains(json_encode($result), 'fixture-private') && !str_contains(json_encode($result), 'sensitive-error'), 'Docker environment and errors never leave the helper');
    capabilityCheck(count($requests) <= 9, 'probe has bounded number of requests');
}
$mode = 'running'; $result = $helper->localContainerPrograms($reader);
capabilityCheck($result['programs']['ffmpeg']['state'] === 'unavailable', 'does not assume FFmpeg exists merely because workstation runs');
$both = $host; $both[0]['installed'] = true;
$programs = LocalContainerCapabilityService::merge($both, $result['programs'], []);
capabilityCheck($programs[0]['state'] === 'host' && $programs[0]['container_available'], 'host and local container are represented independently');
$mode = 'absent'; $result = $helper->localContainerPrograms($reader);
$programs = LocalContainerCapabilityService::merge($host, $result['programs'], [['unit' => 'arcadecloud-workstation.service', 'active' => 'inactive']]);
capabilityCheck($programs[0]['state'] === 'service_stopped' && !$programs[0]['available'], 'removed --rm container is distinguished from a stopped installed service');
$result['programs']['libreoffice'] = ['container' => 'other-node', 'state' => 'container', 'installed' => true, 'available' => true];
$programs = LocalContainerCapabilityService::merge($host, $result['programs'], []);
capabilityCheck($programs[0]['state'] === 'unknown' && !$programs[0]['available'], 'peer or arbitrary container inventory is rejected');
$programs = LocalContainerCapabilityService::merge($host, [], []);
capabilityCheck($programs[0]['state'] === 'unknown', 'missing helper capability remains unknown rather than absent');
echo "Local container capability regression passed.\n";
