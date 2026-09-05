<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sharing;

use RuntimeException;

final class ShareException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $httpStatus = 400
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
