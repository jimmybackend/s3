<?php
declare(strict_types=1);

final class UploadStateStore
{
    private string $dir;
    /** @var array<string,resource> */
    private array $locks = [];

    public static function defaultDirectory(): string
    {
        return __DIR__ . '/state';
    }

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? self::defaultDirectory(), '/\\') . DIRECTORY_SEPARATOR;
    }

    /** Locks are persistent sidecars: never unlink a lock while another process may hold it. */
    public function withLock(string $id, callable $operation, bool $nonBlocking = false): mixed
    {
        $lockPath = $this->dir . '.lock-' . substr(hash('sha256', $id), 0, 2);
        if (isset($this->locks[$lockPath])) return $operation();
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new RuntimeException('No se pudo preparar el directorio de estados.');
        }
        if (is_link($lockPath)) throw new RuntimeException('Lock de subida inválido.');
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) throw new RuntimeException('No se pudo abrir el lock de subida.');
        try {
            if (!flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0))) {
                if ($nonBlocking) return null;
                throw new RuntimeException('No se pudo bloquear la subida.');
            }
            $this->locks[$lockPath] = $handle;
            return $operation();
        } finally {
            unset($this->locks[$lockPath]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function save($id, array $data): void
    {
        $this->withLock((string)$id, function () use ($id, $data): void {
            $data['updated'] = time();
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $temporary = tempnam($this->dir, '.state-');
            if ($temporary === false) throw new RuntimeException('No se pudo preparar el estado.');
            try {
                if (file_put_contents($temporary, $json) === false || !rename($temporary, $this->path($id))) {
                    throw new RuntimeException('No se pudo guardar el estado.');
                }
            } finally {
                if (is_file($temporary)) @unlink($temporary);
            }
        });
    }

    /** Reading alone is non-mutating, including in cleanup dry-run. */
    public function load($id): ?array
    {
        $path = $this->path($id);
        if (is_link($path) || !is_file($path)) return null;
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    public function delete($id): void
    {
        $this->withLock((string)$id, function () use ($id): void {
            $path = $this->path($id);
            if (is_link($path)) throw new RuntimeException('Estado de subida inválido.');
            if (is_file($path) && !unlink($path)) throw new RuntimeException('No se pudo eliminar el estado.');
        });
    }

    public function ids(): array
    {
        return array_map(static fn(string $file): string => basename($file, '.json'), glob($this->dir . '*.json') ?: []);
    }

    public function modifiedAt(string $id): int
    {
        $path = $this->path($id);
        clearstatcache(true, $path);
        return is_link($path) ? 0 : (int)(@filemtime($path) ?: 0);
    }

    private function path($id): string
    {
        $id = (string)$id;
        if ($id === '' || preg_match('/\A[a-zA-Z0-9_.-]+\z/', $id) !== 1) {
            throw new RuntimeException('Identificador de estado inválido.');
        }
        return $this->dir . $id . '.json';
    }
}
