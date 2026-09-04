<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

final class TemporaryZip
{
    public function __construct(
        public readonly string $path,
        public readonly string $downloadName,
        private array $temporaryFiles = []
    ) {
    }

    public function cleanup(): void
    {
        foreach ($this->temporaryFiles as $path) @unlink($path);
        @unlink($this->path);
        $this->temporaryFiles = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
