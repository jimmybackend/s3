<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use RuntimeException;

final class FederationException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 400)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
