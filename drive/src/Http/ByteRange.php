<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http;

final readonly class ByteRange
{
    public function __construct(
        public int $start,
        public int $end,
        public int $length,
        public int $status
    ) {
    }

    public static function parse(?string $header, int $fileSize, int $maxBytes = 1048576): ?self
    {
        if ($fileSize <= 0) return null;
        if ($header === null || trim($header) === '') {
            $end = min($fileSize - 1, $maxBytes - 1);
            return new self(0, $end, $end + 1, 200);
        }
        if (!preg_match('/bytes\s*=\s*(\d*)-(\d*)/i', $header, $match)) return null;
        [$all, $startRaw, $endRaw] = $match;
        if ($startRaw === '' && $endRaw === '') return null;
        if ($startRaw === '') {
            $suffix = (int)$endRaw;
            if ($suffix <= 0) return null;
            $length = min($suffix, $maxBytes, $fileSize);
            $start = max(0, $fileSize - $length);
            return new self($start, $fileSize - 1, $length, 206);
        }
        $start = (int)$startRaw;
        if ($start < 0 || $start >= $fileSize) return null;
        if ($endRaw === '') {
            $end = min($fileSize - 1, $start + $maxBytes - 1);
        } else {
            $requestedEnd = (int)$endRaw;
            if ($requestedEnd < $start) return null;
            $end = min($requestedEnd, $fileSize - 1, $start + $maxBytes - 1);
        }
        return new self($start, $end, ($end - $start) + 1, 206);
    }
}
