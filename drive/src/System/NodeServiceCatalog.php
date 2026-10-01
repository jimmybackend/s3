<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\System;

/** Immutable security boundary shared by diagnostics and service actions. */
final class NodeServiceCatalog
{
    public const COMPONENTS = [
        'nginx' => ['service_name' => 'nginx.service', 'friendly_name' => 'Nginx', 'type' => 'continuous', 'category' => 'critical', 'critical' => true, 'allowed_actions' => []],
        'php-fpm' => ['service_name' => 'php-fpm-drive.service', 'friendly_name' => 'PHP-FPM Drive', 'type' => 'continuous', 'category' => 'critical', 'critical' => true, 'allowed_actions' => []],
        'mysql' => ['service_name' => 'mysql.service', 'friendly_name' => 'MySQL local', 'type' => 'continuous', 'category' => 'critical', 'critical' => true, 'allowed_actions' => []],
        'mariadb' => ['service_name' => 'mariadb.service', 'friendly_name' => 'MariaDB local', 'type' => 'continuous', 'category' => 'critical', 'critical' => true, 'allowed_actions' => []],
        'federation-sync' => ['service_name' => 'arcadecloud-federation-sync.service', 'timer_name' => 'arcadecloud-federation-sync.timer', 'friendly_name' => 'Federation Sync', 'type' => 'oneshot', 'category' => 'federation', 'critical' => false, 'allowed_actions' => ['run-now']],
        'federation-sync-timer' => ['service_name' => 'arcadecloud-federation-sync.timer', 'friendly_name' => 'Federation Sync automático', 'type' => 'timer', 'category' => 'federation', 'critical' => false, 'allowed_actions' => ['enable', 'disable', 'start', 'stop']],
        'federation-https' => ['service_name' => 'arcadecloud-federation-https.service', 'timer_name' => 'arcadecloud-federation-https.timer', 'friendly_name' => 'Federation HTTPS', 'type' => 'oneshot', 'category' => 'federation', 'critical' => false, 'allowed_actions' => ['run-now']],
        'federation-https-timer' => ['service_name' => 'arcadecloud-federation-https.timer', 'friendly_name' => 'Federation HTTPS automático', 'type' => 'timer', 'category' => 'federation', 'critical' => false, 'allowed_actions' => ['enable', 'disable', 'start', 'stop']],
        'federation-cleanup' => ['service_name' => 'arcadecloud-federation-drop-cleanup.service', 'timer_name' => 'arcadecloud-federation-drop-cleanup.timer', 'friendly_name' => 'Federation Drop cleanup', 'type' => 'oneshot', 'category' => 'maintenance', 'critical' => false, 'allowed_actions' => ['run-now']],
        'federation-cleanup-timer' => ['service_name' => 'arcadecloud-federation-drop-cleanup.timer', 'friendly_name' => 'Federation cleanup automático', 'type' => 'timer', 'category' => 'maintenance', 'critical' => false, 'allowed_actions' => ['enable', 'disable', 'start', 'stop']],
        'polly-reconcile' => ['service_name' => 'arcadecloud-polly-reconcile.service', 'timer_name' => 'arcadecloud-polly-reconcile.timer', 'friendly_name' => 'Polly reconcile', 'type' => 'oneshot', 'category' => 'processing', 'critical' => false, 'allowed_actions' => ['run-now']],
        'polly-reconcile-timer' => ['service_name' => 'arcadecloud-polly-reconcile.timer', 'friendly_name' => 'Polly automático', 'type' => 'timer', 'category' => 'processing', 'critical' => false, 'allowed_actions' => ['enable', 'disable', 'start', 'stop']],
        'transcribe-reconcile' => ['service_name' => 'arcadecloud-transcribe-reconcile.service', 'timer_name' => 'arcadecloud-transcribe-reconcile.timer', 'friendly_name' => 'Transcribe reconcile', 'type' => 'oneshot', 'category' => 'processing', 'critical' => false, 'allowed_actions' => ['run-now']],
        'transcribe-reconcile-timer' => ['service_name' => 'arcadecloud-transcribe-reconcile.timer', 'friendly_name' => 'Transcribe automático', 'type' => 'timer', 'category' => 'processing', 'critical' => false, 'allowed_actions' => ['enable', 'disable', 'start', 'stop']],
        'media-worker' => ['service_name' => 'arcadecloud-media-worker.service', 'friendly_name' => 'Media Worker', 'type' => 'continuous', 'category' => 'processing', 'critical' => false, 'allowed_actions' => ['start', 'stop', 'restart', 'enable', 'disable']],
        'media-bootstrap' => ['service_name' => 'arcadecloud-media-node-bootstrap.service', 'friendly_name' => 'Media node bootstrap', 'type' => 'static-helper', 'category' => 'processing', 'critical' => false, 'allowed_actions' => []],
        'workstation' => ['service_name' => 'arcadecloud-workstation.service', 'friendly_name' => 'Workstation / Office', 'type' => 'continuous', 'category' => 'office', 'critical' => false, 'allowed_actions' => ['start', 'stop', 'restart', 'enable', 'disable']],
        'docker' => ['service_name' => 'docker.service', 'friendly_name' => 'Docker', 'type' => 'continuous', 'category' => 'office', 'critical' => true, 'allowed_actions' => []],
    ];

    public static function component(string $id): ?array
    {
        return self::COMPONENTS[$id] ?? null;
    }
}
