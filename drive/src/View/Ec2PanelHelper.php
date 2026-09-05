<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class Ec2PanelHelper
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function tag(array $instance, string $key): string
    {
        foreach (($instance['Tags'] ?? []) as $tag) {
            if (($tag['Key'] ?? '') === $key) {
                return (string)($tag['Value'] ?? '');
            }
        }
        return '';
    }

    public static function isProtected(string $instanceId): bool
    {
        // El panel familiar considera protegidas todas las instancias mostradas.
        return trim($instanceId) !== '';
    }

    public static function isManualDatabase(string $id, array $allowedIds): bool
    {
        return in_array($id, $allowedIds, true);
    }

    public static function databaseStateClass(string $status): string
    {
        if ($status === 'available') return 'state-running';
        if ($status === 'stopped') return 'state-stopped';
        if (in_array($status, ['not_found', 'error'], true)) return 'state-error';
        return 'state-other';
    }

    public static function ipv4ToEc2Dns(string $ipv4): string
    {
        $ipv4 = trim($ipv4);
        if ($ipv4 === '' || !filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return '';
        }
        return 'ec2-' . str_replace('.', '-', $ipv4) . '.compute-1.amazonaws.com';
    }

    public static function defaultRdpContent(): string
    {
        return "auto connect:i:1\r\nfull address:s:\r\nusername:s:Administrator\r\n";
    }

    public static function setRdpFullAddress(string $rdpText, string $host): string
    {
        if (preg_match('/^full address:s:.*$/mi', $rdpText) === 1) {
            return (string)preg_replace(
                '/^full address:s:.*$/mi',
                'full address:s:' . $host,
                $rdpText
            );
        }

        return rtrim($rdpText, "\r\n") . "\r\nfull address:s:" . $host . "\r\n";
    }
}
