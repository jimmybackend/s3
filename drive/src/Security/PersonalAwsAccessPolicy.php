<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

final class PersonalAwsAccessPolicy
{
    public function __construct(private int $ownerUserId = 1)
    {
    }

    public function mayUseAuthenticatedSession(SessionManager $session): bool
    {
        $session->start();
        return $session->isAuthenticated() && $session->userId() === $this->ownerUserId;
    }

    public function authenticatedButForbidden(SessionManager $session): bool
    {
        $session->start();
        return $session->isAuthenticated() && $session->userId() !== $this->ownerUserId;
    }
}
