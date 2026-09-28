<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use RuntimeException;

final class ServerMaintenanceJobStore
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim(
            $directory ?: (string)(getenv('ARCADECLOUD_MAINTENANCE_JOB_DIR') ?: sys_get_temp_dir() . '/arcadecloud-server-maintenance'),
            '/'
        );
        if ($this->directory === '') {
            throw new RuntimeException('Directorio de mantenimiento inválido.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear la cola de mantenimiento.');
        }
        @chmod($this->directory, 0700);
    }

    public function create(int $userId, string $action): array
    {
        if ($userId <= 0 || !in_array($action, ['memory-clear', 'disk-clean'], true)) {
            throw new RuntimeException('Tarea de mantenimiento inválida.');
        }
        $existing = $this->activeForAction($action);
        if ($existing !== null) {
            return $existing + ['already_queued' => true];
        }

        $id = bin2hex(random_bytes(16));
        $now = gmdate('c');
        $job = [
            'id' => $id,
            'user_id' => $userId,
            'action' => $action,
            'status' => 'queued',
            'message' => $action === 'disk-clean'
                ? 'Limpieza segura de disco enviada a la cola.'
                : 'Liberación de memoria enviada a la cola.',
            'created_at' => $now,
            'updated_at' => $now,
            'started_at' => '',
            'completed_at' => '',
            'output' => '',
            'error' => '',
        ];
        $this->writeNew($job);
        return $job;
    }

    public function get(string $id): array
    {
        $path = $this->path($id);
        $raw = @file_get_contents($path);
        $job = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($job)) {
            throw new RuntimeException('Tarea de mantenimiento no encontrada.');
        }
        return $job;
    }

    public function getForUser(int $userId, string $id): array
    {
        $job = $this->get($id);
        if ((int)($job['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('Tarea de mantenimiento no encontrada.');
        }
        return $job;
    }

    public function update(string $id, array $changes): array
    {
        $path = $this->path($id);
        $fh = @fopen($path, 'c+');
        if (!$fh) throw new RuntimeException('No se pudo abrir la tarea de mantenimiento.');
        try {
            if (!flock($fh, LOCK_EX)) throw new RuntimeException('No se pudo bloquear la tarea de mantenimiento.');
            rewind($fh);
            $job = json_decode((string)stream_get_contents($fh), true);
            if (!is_array($job)) throw new RuntimeException('Estado de mantenimiento inválido.');
            foreach ($changes as $key => $value) $job[$key] = $value;
            $job['updated_at'] = gmdate('c');
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            fflush($fh);
            flock($fh, LOCK_UN);
            @chmod($path, 0600);
            return $job;
        } finally {
            fclose($fh);
        }
    }

    public function claimQueued(string $id): ?array
    {
        $job = $this->get($id);
        if ((string)($job['status'] ?? '') !== 'queued') return null;
        return $this->update($id, [
            'status' => 'waiting',
            'message' => 'Esperando a que terminen las tareas activas.',
            'started_at' => gmdate('c'),
        ]);
    }

    public function activeForAction(string $action): ?array
    {
        foreach ($this->all() as $job) {
            if ((string)($job['action'] ?? '') !== $action) continue;
            if (in_array((string)($job['status'] ?? ''), ['queued','waiting','running'], true)) return $job;
        }
        return null;
    }

    public function recentForUser(int $userId, int $limit = 20): array
    {
        $rows = array_values(array_filter(
            $this->all(),
            static fn(array $job): bool => (int)($job['user_id'] ?? 0) === $userId
        ));
        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
        return array_slice($rows, 0, max(1, min(100, $limit)));
    }

    public function cancelForUser(int $userId, string $id): void
    {
        $job = $this->getForUser($userId, $id);
        $status = (string)($job['status'] ?? '');
        if (!in_array($status, ['queued','waiting'], true)) {
            throw new RuntimeException('Esta tarea ya comenzó y no puede cancelarse.');
        }
        $this->update($id, [
            'status' => 'cancelled',
            'message' => (string)($job['action'] ?? '') === 'disk-clean'
                ? 'Limpieza de disco cancelada.'
                : 'Liberación de memoria cancelada.',
            'completed_at' => gmdate('c'),
        ]);
    }

    public function deleteForUser(int $userId, string $id): void
    {
        $job = $this->getForUser($userId, $id);
        if (!in_array((string)($job['status'] ?? ''), ['completed','failed','cancelled'], true)) {
            throw new RuntimeException('Sólo se pueden eliminar tareas terminadas.');
        }
        $path = $this->path($id);
        if (is_file($path) && !@unlink($path)) throw new RuntimeException('No se pudo eliminar la tarea.');
    }

    private function all(): array
    {
        $rows = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $job = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($job)) $rows[] = $job;
        }
        return $rows;
    }

    private function writeNew(array $job): void
    {
        $path = $this->path((string)$job['id']);
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo crear la tarea de mantenimiento.');
        }
        @chmod($path, 0600);
    }

    private function path(string $id): string
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new RuntimeException('Identificador de mantenimiento inválido.');
        return $this->directory . '/' . $id . '.json';
    }
}
