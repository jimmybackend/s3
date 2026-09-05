<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use Aws\S3\S3Client;
use RuntimeException;

final class TextFileService
{
    private const EDITABLE_EXTENSIONS = [
        'txt','srt','vtt','md','markdown','html','htm','css','js','mjs','php','phtml','py',
        'json','csv','sql','jas','xml','yaml','yml','ini','cfg','conf','log'
    ];

    public function __construct(
        private FileRecordLocator $locator,
        private S3Client $s3,
        private string $bucket
    ) {
    }

    public function read(int $userId, string $key): array
    {
        $key = $this->validateKey($key);
        $this->assertEditable($key);
        $file = $this->locator->requireReadableByKey($userId, $key);
        $realKey = (string)$file['_key'];
        $name = (string)$file['Nombre'];

        $head = $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $realKey]);
        $contentType = isset($head['ContentType']) ? (string)$head['ContentType'] : null;
        if (!$this->isTextLikeByName($name) && !$this->isTextLikeContentType($contentType)) {
            throw new RuntimeException('Este archivo no parece ser de texto editable.');
        }

        $object = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $realKey]);
        return [
            'id' => (int)$file['id_'],
            'nombre' => $name,
            'key_s3' => $realKey,
            'contenido' => (string)$object['Body'],
            'contentType' => $contentType,
            'lenguaje' => $this->detectLanguage($name),
        ];
    }

    public function save(int $userId, string $key, string $content): array
    {
        $key = $this->validateKey($key);
        $this->assertEditable($key);
        $file = $this->locator->requireReadableByKey($userId, $key);
        $realKey = (string)$file['_key'];
        $name = (string)$file['Nombre'];

        $head = $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $realKey]);
        $currentType = isset($head['ContentType']) ? (string)$head['ContentType'] : null;
        if (!$this->isTextLikeByName($name) && !$this->isTextLikeContentType($currentType)) {
            throw new RuntimeException('Este archivo no parece ser de texto editable.');
        }

        $saveType = $this->contentTypeForName($name);
        if ($saveType === 'text/plain; charset=utf-8' && $this->isTextLikeContentType($currentType)) {
            $saveType = (string)$currentType;
        }

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $realKey,
            'Body' => $content,
            'ACL' => 'private',
            'ContentType' => $saveType,
        ]);

        return ['id' => (int)$file['id_'], 'key_s3' => $realKey, 'estado' => 'actualizado'];
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

    private function contentTypeForName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'json' => 'application/json; charset=utf-8',
            'html', 'htm' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs', 'cjs', 'jas' => 'application/javascript; charset=utf-8',
            'md' => 'text/markdown; charset=utf-8',
            'csv' => 'text/csv; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'yml', 'yaml' => 'text/yaml; charset=utf-8',
            default => 'text/plain; charset=utf-8',
        };
    }

    private function isTextLikeContentType(?string $type): bool
    {
        $type = strtolower(trim((string)$type));
        if ($type === '') return false;
        return str_starts_with($type, 'text/')
            || str_starts_with($type, 'application/json')
            || str_starts_with($type, 'application/xml')
            || str_starts_with($type, 'application/javascript')
            || str_starts_with($type, 'application/x-javascript')
            || str_starts_with($type, 'application/sql')
            || str_starts_with($type, 'application/x-httpd-php')
            || str_starts_with($type, 'application/x-sh')
            || str_starts_with($type, 'application/x-yaml');
    }

    private function isTextLikeByName(string $name): bool
    {
        $base = strtolower(basename(str_replace('\\', '/', trim($name))));
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (in_array($base, ['dockerfile','makefile','.env','.gitignore','.htaccess','readme','readme.md','readme.txt'], true)) return true;
        return in_array($ext, [
            'txt','text','log','md','markdown','rst','ini','conf','config','cfg','env','csv','tsv','json','jsonl','xml','yaml','yml','toml','properties','sql',
            'html','htm','css','scss','sass','less','js','mjs','cjs','jas','ts','tsx','jsx','php','phtml','inc','phar','py','rb','java','kt','kts','groovy',
            'scala','lua','pl','pm','r','dart','go','rs','swift','c','h','cpp','hpp','cc','hh','cxx','hxx','cs','vb','sh','bash','zsh','fish','bat','cmd','ps1',
            'srt','vtt','ass','ssa','vue','astro','twig','blade','latte','mustache'
        ], true);
    }

    private function detectLanguage(string $name): string
    {
        $base = strtolower(basename(str_replace('\\', '/', trim($name))));
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $map = [
            'txt'=>'plaintext','text'=>'plaintext','log'=>'plaintext','md'=>'markdown','markdown'=>'markdown','html'=>'html','htm'=>'html','css'=>'css','scss'=>'scss',
            'less'=>'less','js'=>'javascript','mjs'=>'javascript','cjs'=>'javascript','jas'=>'javascript','jsx'=>'javascript','ts'=>'typescript','tsx'=>'typescript',
            'json'=>'json','jsonl'=>'json','xml'=>'xml','yaml'=>'yaml','yml'=>'yaml','toml'=>'ini','ini'=>'ini','conf'=>'ini','cfg'=>'ini','php'=>'php','phtml'=>'php',
            'inc'=>'php','py'=>'python','rb'=>'ruby','java'=>'java','c'=>'c','h'=>'c','cpp'=>'cpp','hpp'=>'cpp','cs'=>'csharp','go'=>'go','rs'=>'rust','swift'=>'swift',
            'kt'=>'kotlin','kts'=>'kotlin','sh'=>'shell','bash'=>'shell','zsh'=>'shell','bat'=>'bat','cmd'=>'bat','ps1'=>'powershell','sql'=>'sql','csv'=>'plaintext',
            'tsv'=>'plaintext','srt'=>'plaintext','vtt'=>'plaintext','vue'=>'html'
        ];
        if ($base === 'dockerfile') return 'dockerfile';
        if ($base === 'makefile') return 'makefile';
        if (in_array($base, ['.env','.gitignore','.htaccess'], true)) return 'shell';
        return $map[$ext] ?? 'plaintext';
    }
}
