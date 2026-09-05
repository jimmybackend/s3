<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use ArcadeCloud\Drive\Aws\PersonalAwsConfig;

final class PersonalToolAccessService
{
    private const PRIVATE_SESSION_KEY = 'personal_tools_unlocked';

    public function __construct(
        private SessionManager $session,
        private PersonalAwsConfig $config,
        private int $ownerUserId = 1
    ) {
    }

    public function state(): string
    {
        $this->session->start();

        if ($this->session->isAuthenticated()) {
            return $this->session->userId() === $this->ownerUserId ? 'owner' : 'forbidden';
        }

        return $this->session->get(self::PRIVATE_SESSION_KEY, false) === true
            ? 'private'
            : 'locked';
    }

    public function unlock(string $candidate): bool
    {
        $this->session->start();

        if ($this->session->isAuthenticated()) {
            return false;
        }

        $storedHash = $this->config->passwordHash();
        if ($candidate === '' || $storedHash === '') {
            return false;
        }

        if (!password_verify($candidate, $storedHash)) {
            return false;
        }

        $this->session->set(self::PRIVATE_SESSION_KEY, true);
        return true;
    }
}
