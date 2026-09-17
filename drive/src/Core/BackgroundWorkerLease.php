<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Core;

use RuntimeException;

/**
 * Lock de vida por job para saber si un worker CLI sigue realmente activo.
 *
 * El lock vive fuera de la base de datos y no representa estado de negocio;
 * sólo evita confundir un JSON huérfano con un proceso vivo.
 */
final class BackgroundWorkerLease
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?: sys_get_temp_dir() . '/arcadecloud-worker-leases', '/');
        if ($this->directory === '') {
            throw new RuntimeException('Directorio de leases inválido.');
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear el directorio de leases.');
        }
    }

    /** @return resource|null */
    public function acquire(string $kind, string $jobId)
    {
        $path = $this->path($kind, $jobId);
        $handle = @fopen($path, 'c+');
        if (!$handle) {
            throw new RuntimeException('No se pudo abrir el lease del worker.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        @chmod($path, 0660);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode([
            'pid' => getmypid(),
            'kind' => strtolower($kind),
            'job_id' => strtolower($jobId),
            'started_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES));
        fflush($handle);

        return $handle;
    }

    public function isActive(string $kind, string $jobId): bool
    {
        $path = $this->path($kind, $jobId);
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'c+');
        if (!$handle) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }

            flock($handle, LOCK_UN);
            return false;
        } finally {
            fclose($handle);
        }
    }

    private function path(string $kind, string $jobId): string
    {
        $kind = strtolower(trim($kind));
        $jobId = strtolower(trim($jobId));

        if (!in_array($kind, ['sync', 'move'], true)) {
            throw new RuntimeException('Tipo de worker inválido.');
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new RuntimeException('Identificador de job inválido.');
        }

        return $this->directory . '/' . $kind . '-' . $jobId . '.lock';
    }
}
