<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Upload;

use RuntimeException;

/**
 * Estado persistente del Centro de Tareas para subidas.
 *
 * No guarda URLs prefirmadas, tokens ni credenciales. El archivo contiene
 * únicamente estado visual/operativo y se separa por usuario.
 */
final class UploadTaskStore
{
    private const RETENTION_SECONDS = 604800; // 7 días

    public function __construct(private string $directory)
    {
        $this->directory = rtrim($this->directory, '/');
        if ($this->directory === '') {
            throw new RuntimeException('Directorio de tareas de subida inválido.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear el directorio de tareas de subida.');
        }
        @chmod($this->directory, 0700);
    }

    public function put(int $userId, string $id, array $changes): array
    {
        if ($userId <= 0) throw new RuntimeException('Usuario de tarea de subida inválido.');
        $id = $this->normalizeId($id);
        $path = $this->path($id);
        $fh = @fopen($path, 'c+');
        if (!$fh) throw new RuntimeException('No se pudo abrir la tarea de subida.');

        try {
            if (!flock($fh, LOCK_EX)) throw new RuntimeException('No se pudo bloquear la tarea de subida.');
            rewind($fh);
            $raw = stream_get_contents($fh);
            $job = $raw !== '' ? json_decode((string)$raw, true) : null;
            if (!is_array($job)) {
                $now = gmdate('c');
                $job = [
                    'id' => $id,
                    'user_id' => $userId,
                    'status' => 'queued',
                    'title' => 'Subida',
                    'detail' => 'Subida registrada en Tareas.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } elseif ((int)($job['user_id'] ?? 0) !== $userId) {
                throw new RuntimeException('La tarea de subida pertenece a otro usuario.');
            }

            foreach ($this->sanitizeChanges($changes) as $key => $value) {
                $job[$key] = $value;
            }
            $job['updated_at'] = gmdate('c');
            if (in_array((string)($job['status'] ?? ''), ['completed', 'failed', 'cancelled'], true)
                && empty($job['completed_at'])) {
                $job['completed_at'] = $job['updated_at'];
            }

            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            fflush($fh);
            flock($fh, LOCK_UN);
            @chmod($path, 0600);
        } finally {
            fclose($fh);
        }

        $this->purgeOlderThan(self::RETENTION_SECONDS);
        return $job;
    }

    public function recentForUser(int $userId, int $limit = 50): array
    {
        if ($userId <= 0) return [];
        $rows = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $job = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($job) || (int)($job['user_id'] ?? 0) !== $userId) continue;
            $rows[] = $job;
        }
        usort($rows, static fn(array $a, array $b): int =>
            strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''))
        );
        return array_slice($rows, 0, max(1, min(150, $limit)));
    }

    public function deleteForUser(int $userId, string $id): void
    {
        $id = $this->normalizeId($id);
        $path = $this->path($id);
        $raw = @file_get_contents($path);
        $job = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($job) || (int)($job['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('Tarea de subida no encontrada.');
        }
        $status = strtolower((string)($job['status'] ?? ''));
        if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            throw new RuntimeException('Una subida activa no se puede eliminar de Tareas.');
        }
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('No se pudo eliminar la tarea de subida.');
        }
    }

    private function sanitizeChanges(array $changes): array
    {
        $out = [];
        $stringFields = [
            'title' => 255,
            'detail' => 500,
            'source' => 80,
            'upload_mode' => 40,
            'destination' => 500,
            'service' => 80,
            'provider' => 80,
        ];
        foreach ($stringFields as $key => $max) {
            if (!array_key_exists($key, $changes)) continue;
            $out[$key] = mb_substr(trim((string)$changes[$key]), 0, $max);
        }

        if (array_key_exists('status', $changes)) {
            $status = strtolower(trim((string)$changes['status']));
            $out['status'] = in_array($status, ['queued','pending','running','completed','failed','cancelled'], true)
                ? $status
                : 'pending';
        }
        if (array_key_exists('progress', $changes)) {
            $progress = $changes['progress'];
            $out['progress'] = $progress === null ? null : max(0, min(100, (int)$progress));
        }
        foreach (['bytes_total','bytes_uploaded','speed_bps','eta_seconds','parts_total','part_number'] as $key) {
            if (array_key_exists($key, $changes)) $out[$key] = max(0, (int)$changes[$key]);
        }
        if (array_key_exists('completed_at', $changes)) {
            $out['completed_at'] = mb_substr(trim((string)$changes['completed_at']), 0, 64);
        }
        return $out;
    }

    private function normalizeId(string $id): string
    {
        $id = trim($id);
        if ($id === '' || strlen($id) > 160 || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            throw new RuntimeException('Identificador de tarea de subida inválido.');
        }
        return $id;
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . hash('sha256', $id) . '.json';
    }

    private function purgeOlderThan(int $seconds): void
    {
        $cutoff = time() - max(3600, $seconds);
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) @unlink($path);
        }
    }
}
