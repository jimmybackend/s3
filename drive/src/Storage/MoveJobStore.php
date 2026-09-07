<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use RuntimeException;

final class MoveJobStore
{
    public function __construct(private string $directory)
    {
        $this->directory = rtrim($this->directory, '/');
        if ($this->directory === '') {
            throw new RuntimeException('Directorio de tareas de movimiento inválido.');
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear el directorio de tareas de movimiento.');
        }

        @chmod($this->directory, 0700);
    }

    public function create(int $userId, string $type, array $payload): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $type = trim($type);
        if (!in_array($type, ['files', 'folder'], true)) {
            throw new RuntimeException('Tipo de tarea de movimiento inválido.');
        }

        $id = bin2hex(random_bytes(16));
        $now = gmdate('c');
        $job = [
            'id' => $id,
            'user_id' => $userId,
            'type' => $type,
            'status' => 'queued',
            'message' => 'Movimiento enviado a segundo plano.',
            'payload' => $payload,
            'result' => null,
            'error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->writeNew($id, $job);
        $this->purgeOlderThan(172800);

        return $job;
    }

    public function getForUser(int $userId, string $id): array
    {
        $job = $this->get($id);
        if ((int)($job['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('Tarea de movimiento no encontrada.');
        }
        return $job;
    }

    public function get(string $id): array
    {
        $path = $this->path($id);
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException('Tarea de movimiento no encontrada.');
        }

        try {
            if (!flock($fh, LOCK_SH)) {
                throw new RuntimeException('No se pudo bloquear la tarea de movimiento.');
            }
            $raw = stream_get_contents($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Estado de tarea de movimiento inválido.');
        }
        return $data;
    }

    public function update(string $id, array $changes): array
    {
        $path = $this->path($id);
        $fh = @fopen($path, 'c+');
        if (!$fh) {
            throw new RuntimeException('No se pudo abrir la tarea de movimiento.');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear la tarea de movimiento.');
            }

            rewind($fh);
            $raw = stream_get_contents($fh);
            $job = json_decode((string)$raw, true);
            if (!is_array($job)) {
                throw new RuntimeException('Estado de tarea de movimiento inválido.');
            }

            foreach ($changes as $key => $value) {
                $job[$key] = $value;
            }
            $job['updated_at'] = gmdate('c');

            rewind($fh);
            ftruncate($fh, 0);
            $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            fwrite($fh, $json);
            fflush($fh);
            flock($fh, LOCK_UN);
            @chmod($path, 0600);

            return $job;
        } finally {
            fclose($fh);
        }
    }

    private function writeNew(string $id, array $job): void
    {
        $path = $this->path($id);
        $fh = @fopen($path, 'x');
        if (!$fh) {
            throw new RuntimeException('No se pudo crear la tarea de movimiento.');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear la nueva tarea de movimiento.');
            }
            $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            fwrite($fh, $json);
            fflush($fh);
            flock($fh, LOCK_UN);
            @chmod($path, 0600);
        } finally {
            fclose($fh);
        }
    }

    private function path(string $id): string
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new RuntimeException('Identificador de tarea inválido.');
        }
        return $this->directory . '/' . $id . '.json';
    }

    private function purgeOlderThan(int $seconds): void
    {
        $cutoff = time() - max(3600, $seconds);
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($path);
            }
        }
    }
}
