<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use ArcadeCloud\Drive\Core\BackgroundWorkerLease;
use RuntimeException;

final class MoveJobStore
{
    private const QUEUED_STALE_SECONDS = 120;
    private const ACTIVE_STALE_SECONDS = 1800;

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

            $job = $this->decodeLocked($fh);
            foreach ($changes as $key => $value) {
                $job[$key] = $value;
            }
            $job['updated_at'] = gmdate('c');
            $this->writeLocked($fh, $job, $path);
            return $job;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Reclama atómicamente un job queued para evitar workers duplicados.
     * Devuelve null cuando otra ejecución ya lo reclamó o el job ya terminó.
     */
    public function claimQueued(string $id): ?array
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

            $job = $this->decodeLocked($fh);
            if ((string)($job['status'] ?? '') !== 'queued') {
                flock($fh, LOCK_UN);
                return null;
            }

            $job['status'] = 'running';
            $job['message'] = 'Movimiento en proceso.';
            $job['error'] = null;
            $job['updated_at'] = gmdate('c');
            $this->writeLocked($fh, $job, $path);
            return $job;
        } finally {
            fclose($fh);
        }
    }

    public function deleteForUser(int $userId, string $id): void
    {
        $job = $this->getForUser($userId, $id);
        $status = strtolower((string)($job['status'] ?? ''));

        if (in_array($status, ['queued', 'pending'], true)) {
            $lease = new BackgroundWorkerLease();
            if ($lease->isActive('move', $id)) {
                throw new RuntimeException('El traslado ya está siendo tomado por un worker. Deténlo antes de eliminarlo.');
            }
        } elseif (!in_array($status, ['completed', 'failed', 'error', 'cancelled'], true)) {
            throw new RuntimeException('Sólo se pueden eliminar traslados en cola sin worker o tareas terminadas, fallidas o canceladas.');
        }

        $path = $this->path($id);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('No se pudo eliminar la tarea de movimiento.');
        }
    }

    /**
     * Lista las tareas recientes del usuario para el centro unificado.
     * Los estados activos huérfanos se convierten a failed cuando el worker
     * ya no conserva su lease y el estado dejó de actualizarse.
     */
    public function recentForUser(int $userId, int $limit = 30): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $rows = [];

        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
                continue;
            }

            try {
                $job = $this->get($id);
                $job = $this->reconcileStaleJob($job);
            } catch (\Throwable) {
                continue;
            }

            if ((int)($job['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $rows[] = $job;
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });

        return array_slice($rows, 0, $limit);
    }

    private function reconcileStaleJob(array $job): array
    {
        $status = strtolower((string)($job['status'] ?? ''));
        if (!in_array($status, ['queued', 'pending', 'running', 'cancel_requested'], true)) {
            return $job;
        }

        $id = strtolower((string)($job['id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return $job;
        }

        $updatedAt = strtotime((string)($job['updated_at'] ?? $job['created_at'] ?? ''));
        if ($updatedAt === false) {
            return $job;
        }

        $threshold = in_array($status, ['queued', 'pending'], true)
            ? self::QUEUED_STALE_SECONDS
            : self::ACTIVE_STALE_SECONDS;
        if (time() - $updatedAt < $threshold) {
            return $job;
        }

        $lease = new BackgroundWorkerLease();
        if ($lease->isActive('move', $id)) {
            return $job;
        }

        return $this->update($id, [
            'status' => 'failed',
            'message' => 'Proceso interrumpido: el worker ya no está activo. Puedes quitar esta tarea de Tareas.',
            'error' => 'Worker huérfano detectado por el servidor.',
            'finished_at' => gmdate('c'),
            'interrupted_at' => gmdate('c'),
        ]);
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

    private function decodeLocked($fh): array
    {
        rewind($fh);
        $raw = stream_get_contents($fh);
        $job = json_decode((string)$raw, true);
        if (!is_array($job)) {
            throw new RuntimeException('Estado de tarea de movimiento inválido.');
        }
        return $job;
    }

    private function writeLocked($fh, array $job, string $path): void
    {
        rewind($fh);
        ftruncate($fh, 0);
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        fwrite($fh, $json);
        fflush($fh);
        flock($fh, LOCK_UN);
        @chmod($path, 0600);
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
