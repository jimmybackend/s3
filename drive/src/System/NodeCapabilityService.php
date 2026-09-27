<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\System;

final class NodeCapabilityService
{
    private const IMDS_BASE = 'http://169.254.169.254/latest';

    public function snapshot(string $diskPath): array
    {
        $diskPath = is_dir($diskPath) ? $diskPath : '/';
        $memory = $this->readMemInfo();
        $imds = $this->readImdsV2();

        $diskTotal = @disk_total_space($diskPath);
        $diskFree = @disk_free_space($diskPath);

        return [
            'hostname' => (string)(gethostname() ?: ''),
            'role' => strtolower(trim((string)(getenv('ARCADECLOUD_NODE_ROLE') ?: 'web'))),
            'instance_id' => (string)($imds['instance_id'] ?? ''),
            'instance_type' => (string)($imds['instance_type'] ?? ''),
            'availability_zone' => (string)($imds['availability_zone'] ?? ''),
            'vcpu' => $this->cpuCount(),
            'memory_total_bytes' => (int)($memory['MemTotal'] ?? 0),
            'memory_available_bytes' => (int)($memory['MemAvailable'] ?? 0),
            'swap_total_bytes' => (int)($memory['SwapTotal'] ?? 0),
            'swap_free_bytes' => (int)($memory['SwapFree'] ?? 0),
            'disk_total_bytes' => is_float($diskTotal) || is_int($diskTotal) ? (int)$diskTotal : 0,
            'disk_free_bytes' => is_float($diskFree) || is_int($diskFree) ? (int)$diskFree : 0,
            'load_average' => $this->loadAverage(),
            'docker_installed' => $this->findExecutable('docker') !== null,
            'docker_socket_available' => is_readable('/var/run/docker.sock') || is_writable('/var/run/docker.sock'),
            'ffmpeg_available' => $this->findExecutable('ffmpeg') !== null,
            'ffprobe_available' => $this->findExecutable('ffprobe') !== null,
            'gpu_present' => $this->gpuPresent(),
        ];
    }

    /**
     * Evalúa requisitos declarativos sin decidir por nombre o rol del nodo.
     *
     * Requisitos soportados:
     * - min_vcpu
     * - min_memory_bytes
     * - min_memory_available_bytes
     * - min_disk_free_bytes
     * - requires_docker
     * - requires_gpu
     * - commands (lista de ejecutables)
     */
    public function evaluate(array $requirements, ?array $snapshot = null, string $diskPath = '/'): array
    {
        $snapshot ??= $this->snapshot($diskPath);
        $reasons = [];

        $minVcpu = max(0, (int)($requirements['min_vcpu'] ?? 0));
        if ($minVcpu > 0 && (int)($snapshot['vcpu'] ?? 0) < $minVcpu) {
            $reasons[] = 'vcpu';
        }

        $minMemory = max(0, (int)($requirements['min_memory_bytes'] ?? 0));
        if ($minMemory > 0 && (int)($snapshot['memory_total_bytes'] ?? 0) < $minMemory) {
            $reasons[] = 'memory_total';
        }

        $minAvailable = max(0, (int)($requirements['min_memory_available_bytes'] ?? 0));
        if ($minAvailable > 0 && (int)($snapshot['memory_available_bytes'] ?? 0) < $minAvailable) {
            $reasons[] = 'memory_available';
        }

        $minDisk = max(0, (int)($requirements['min_disk_free_bytes'] ?? 0));
        if ($minDisk > 0 && (int)($snapshot['disk_free_bytes'] ?? 0) < $minDisk) {
            $reasons[] = 'disk_free';
        }

        if (($requirements['requires_docker'] ?? false) === true && ($snapshot['docker_installed'] ?? false) !== true) {
            $reasons[] = 'docker';
        }

        if (($requirements['requires_gpu'] ?? false) === true && ($snapshot['gpu_present'] ?? false) !== true) {
            $reasons[] = 'gpu';
        }

        $commands = $requirements['commands'] ?? [];
        if (is_array($commands)) {
            foreach ($commands as $command) {
                $command = trim((string)$command);
                if ($command !== '' && $this->findExecutable($command) === null) {
                    $reasons[] = 'command:' . $command;
                }
            }
        }

        return [
            'ready' => $reasons === [],
            'state' => $reasons === [] ? 'ready' : 'insufficient_capacity',
            'reasons' => array_values(array_unique($reasons)),
            'snapshot' => $snapshot,
        ];
    }

    private function cpuCount(): int
    {
        $online = @file_get_contents('/sys/devices/system/cpu/online');
        if (is_string($online) && trim($online) !== '') {
            $count = 0;
            foreach (explode(',', trim($online)) as $part) {
                if (preg_match('/\A(\d+)-(\d+)\z/', $part, $m)) {
                    $count += max(0, ((int)$m[2] - (int)$m[1]) + 1);
                } elseif (ctype_digit($part)) {
                    $count++;
                }
            }
            if ($count > 0) {
                return $count;
            }
        }

        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo)) {
            preg_match_all('/^processor\s*:/m', $cpuInfo, $matches);
            if (isset($matches[0]) && count($matches[0]) > 0) {
                return count($matches[0]);
            }
        }

        return 1;
    }

    private function readMemInfo(): array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if (!is_string($raw)) {
            return [];
        }

        $wanted = ['MemTotal', 'MemAvailable', 'SwapTotal', 'SwapFree'];
        $result = [];
        foreach (explode("\n", $raw) as $line) {
            if (!preg_match('/\A([A-Za-z]+):\s+(\d+)\s+kB\z/', trim($line), $m)) {
                continue;
            }
            if (in_array($m[1], $wanted, true)) {
                $result[$m[1]] = (int)$m[2] * 1024;
            }
        }
        return $result;
    }

    private function loadAverage(): array
    {
        $load = sys_getloadavg();
        if (!is_array($load)) {
            return [0.0, 0.0, 0.0];
        }
        return [
            round((float)($load[0] ?? 0.0), 2),
            round((float)($load[1] ?? 0.0), 2),
            round((float)($load[2] ?? 0.0), 2),
        ];
    }

    private function gpuPresent(): bool
    {
        $nvidia = glob('/dev/nvidia*');
        if (is_array($nvidia) && $nvidia !== []) {
            return true;
        }

        $dri = glob('/dev/dri/renderD*');
        return is_array($dri) && $dri !== [];
    }

    private function findExecutable(string $name): ?string
    {
        if ($name === '' || str_contains($name, '/')) {
            return null;
        }

        $path = (string)(getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function readImdsV2(): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }

        $token = $this->curlMetadata(
            self::IMDS_BASE . '/api/token',
            [],
            true
        );
        if ($token === '') {
            return [];
        }

        $headers = ['X-aws-ec2-metadata-token: ' . $token];

        return [
            'instance_id' => $this->curlMetadata(self::IMDS_BASE . '/meta-data/instance-id', $headers),
            'instance_type' => $this->curlMetadata(self::IMDS_BASE . '/meta-data/instance-type', $headers),
            'availability_zone' => $this->curlMetadata(self::IMDS_BASE . '/meta-data/placement/availability-zone', $headers),
        ];
    }

    private function curlMetadata(string $url, array $headers = [], bool $putToken = false): string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return '';
        }

        $headers[] = 'Accept: text/plain';
        if ($putToken) {
            $headers[] = 'X-aws-ec2-metadata-token-ttl-seconds: 60';
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 250,
            CURLOPT_TIMEOUT_MS => 500,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($putToken) {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
        }

        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            return '';
        }

        return trim($body);
    }
}
