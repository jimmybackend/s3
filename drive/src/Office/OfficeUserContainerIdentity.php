<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use InvalidArgumentException;

/**
 * Stable container and persistent storage identifiers for a future
 * per-user Office broker. This class does NOT create containers, grant
 * access or change the current single-user Workstation.
 *
 * User ID MUST come from the authenticated server-side ArcadeCloud session,
 * never from a browser parameter, token claim or username supplied by client.
 */
final class OfficeUserContainerIdentity
{
    private const STATE_ROOT = '/var/lib/arcadecloud-office/users';

    public function __construct(private int $userId)
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Se necesita un usuario ArcadeCloud autenticado.');
        }
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function containerName(): string
    {
        return 'arcadecloud-office-u' . $this->userId;
    }

    public function directory(): string
    {
        return self::STATE_ROOT . '/u' . $this->userId;
    }

    public function homeDirectory(): string
    {
        return $this->directory() . '/home';
    }

    public function workspaceDirectory(): string
    {
        return $this->directory() . '/workspace';
    }

    public function stateDirectory(): string
    {
        return $this->directory() . '/state';
    }

    /**
     * Logical key only: a trusted broker must resolve it against the
     * authenticated session and a bounded per-user resource scheduler.
     */
    public function routingKey(): string
    {
        return 'office-user-' . $this->userId;
    }
}
