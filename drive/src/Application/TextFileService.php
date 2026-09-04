<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use RuntimeException;

final class TextFileService
{
    private const EDITABLE_EXTENSIONS = [
        'txt','srt','vtt','md','markdown','html','htm','css','js','mjs','php','phtml','py',
        'json','csv','sql','jas','xml','yaml','yml','ini','cfg','conf','log'
    ];

    public function __construct(private \S3Manager $storage)
    {
    }

    public function read(string $key): mixed
    {
        $key = $this->validateKey($key);
        $this->assertEditable($key);
        return $this->storage->getTextFile($key);
    }

    public function save(string $key, string $content): mixed
    {
        $key = $this->validateKey($key);
        $this->assertEditable($key);
        return $this->storage->updateTextFile($key, $content);
    }

    private function validateKey(string $key): string
    {
        $key = trim(str_replace('\\', '/', $key));
        if ($key === '' || str_contains($key, '../') || str_starts_with($key, '/')) {
            throw new RuntimeException('Clave de archivo inválida.');
        }
        return $key;
    }

    private function assertEditable(string $key): void
    {
        $extension = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EDITABLE_EXTENSIONS, true)) {
            throw new RuntimeException('El tipo de archivo no es editable como texto.');
        }
    }
}
