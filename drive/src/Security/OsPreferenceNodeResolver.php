<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\NodeIdentityService;
use Throwable;

final class OsPreferenceNodeResolver
{
    public static function resolve(): string
    {
        try {
            $config = FederationConfig::fromEnvironment();
            $identity = new NodeIdentityService($config->identityPath());
            $nodeId = trim($identity->nodeId());
            if ($nodeId !== '') {
                return $nodeId;
            }
        } catch (Throwable) {
            // Un nodo sin identidad FederationCloud todavía debe aislar preferencias.
        }

        $host = trim((string)(getenv('ARCADECLOUD_PUBLIC_URL') ?: ''));
        if ($host !== '') {
            $parsed = parse_url($host, PHP_URL_HOST);
            if (is_string($parsed) && trim($parsed) !== '') {
                $host = $parsed;
            }
        }
        if ($host === '') {
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        }
        if ($host === '') {
            $host = trim((string)(gethostname() ?: 'arcadecloud'));
        }

        $host = strtolower(preg_replace('/[^a-z0-9._:-]+/i', '-', $host) ?? $host);
        return 'host:' . substr($host, 0, 220);
    }
}
