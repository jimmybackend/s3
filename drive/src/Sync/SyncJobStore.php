<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sync;

use RuntimeException;

final class SyncJobStore
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?: sys_get_temp_dir() . '/arcadecloud-sync-jobs';

        if (!is_dir($this->dir)) {
            if (!@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
                throw new RuntimeException('No se pudo crear directorio de jobs.');
            }
        }
    }

    public function create(int $userId, string $jobId): array
    {
        $this->validateJobId($jobId);
        $this->cleanupOld();

        $data = [
            'job_id' => $jobId,
            'user_id' => $userId,
            'state' => 'queued',
            'batch' => 0,
            'files' => 0,
            'folders' => 0,
            'message' => 'En cola',
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $this->write($userId, $jobId, $data);

        return $data;
    }

    public function read(int $userId, string $jobId): ?array
    {
        $this->validateJobId($jobId);

        $path = $this->path($userId, $jobId);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public function update(int $userId, string $jobId, array $changes): array
    {
        $current = $this->read($userId, $jobId) ?? [
            'job_id' => $jobId,
            'user_id' => $userId,
        ];

        $data = array_merge(
            $current,
            $changes,
            ['updated_at' => date('c')]
        );

        $this->write($userId, $jobId, $data);

        return $data;
    }

    public function lockPath(int $userId): string
    {
        return $this->dir . '/user-' . $userId . '.lock';
    }

    private function path(int $userId, string $jobId): string
    {
        return $this->dir . '/' . $userId . '-' . $jobId . '.json';
    }

    private function validateJobId(string $jobId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new RuntimeException('Job inválido.');
        }
    }

    private function write(int $userId, string $jobId, array $data): void
    {
        $path = $this->path($userId, $jobId);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar estado.');
        }

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar estado.');
        }

        @chmod($tmp, 0660);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo publicar estado.');
        }
    }

    private function cleanupOld(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $mtime = @filemtime($file);

            if ($mtime && $mtime < time() - 172800) {
                @unlink($file);
            }
        }
    }
}
