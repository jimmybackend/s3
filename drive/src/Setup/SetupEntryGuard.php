<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Setup;

final class SetupEntryGuard
{
    public const DEFAULT_AUTH_PATH = '/etc/arcadecloud-drive/bootstrap-auth.json';
    public const DEFAULT_LOCK_PATH = '/etc/arcadecloud-drive/setup.lock';

    public function __construct(
        private string $authPath = self::DEFAULT_AUTH_PATH,
        private string $lockPath = self::DEFAULT_LOCK_PATH
    ) {
    }

    public function isSetupPending(): bool
    {
        return is_file($this->authPath) && !is_file($this->lockPath);
    }
}
