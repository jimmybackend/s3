<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use RuntimeException;

final class FolderTextractJobStore
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim(
            $directory ?: (string)(getenv('ARCADECLOUD_FOLDER_TEXTRACT_JOB_DIR') ?: sys_get_temp_dir() . '/arcadecloud-folder-textract'),
            '/'
        );
        if ($this->directory === '') throw new RuntimeException('Directorio de tareas Textract inválido.');
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear la cola de extracción de carpetas.');
        }
        @chmod($this->directory, 0700);
    }

    public function create(int $userId, string $route, string $name): array
    {
        if ($userId <= 0 || trim($route) === '') throw new RuntimeException('Tarea de extracción inválida.');
        $id = bin2hex(random_bytes(16));
        $now = gmdate('c');
        $job = [
            'id' => $id,
            'user_id' => $userId,
            'route' => $route,
            'name' => trim($name),
            'status' => 'queued',
            'message' => 'Extracción enviada a Tareas.',
            'total' => 0,
            'processed' => 0,
            'current_file' => '',
            'output_name' => '',
            'output_route' => '',
            'estimated_cost' => null,
            'currency' => 'USD',
            'created_at' => $now,
            'updated_at' => $now,
            'started_at' => '',
            'completed_at' => '',
            'error' => '',
        ];
        $this->writeNew($job);
        return $job;
    }

    public function get(string $id): array
    {
        $raw = @file_get_contents($this->path($id));
        $job = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($job)) throw new RuntimeException('Tarea de extracción no encontrada.');
        return $job;
    }

    public function getForUser(int $userId, string $id): array
    {
        $job = $this->get($id);
        if ((int)($job['user_id'] ?? 0) !== $userId) throw new RuntimeException('Tarea de extracción no encontrada.');
        return $job;
    }

    public function update(string $id, array $changes): array
    {
        $path = $this->path($id);
        $fh = @fopen($path, 'c+');
        if (!$fh) throw new RuntimeException('No se pudo abrir la tarea de extracción.');
        try {
            if (!flock($fh, LOCK_EX)) throw new RuntimeException('No se pudo bloquear la tarea de extracción.');
            rewind($fh);
            $job = json_decode((string)stream_get_contents($fh), true);
            if (!is_array($job)) throw new RuntimeException('Estado de extracción inválido.');
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

    public function recentForUser(int $userId, int $limit = 40): array
    {
        $rows = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $job = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($job) && (int)($job['user_id'] ?? 0) === $userId) $rows[] = $job;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
        return array_slice($rows, 0, max(1, min(100, $limit)));
    }

    public function cancelForUser(int $userId, string $id): void
    {
        $job = $this->getForUser($userId, $id);
        if (!in_array((string)($job['status'] ?? ''), ['queued'], true)) {
            throw new RuntimeException('La extracción ya comenzó y no puede cancelarse desde la cola.');
        }
        $this->update($id, [
            'status' => 'cancelled',
            'message' => 'Extracción cancelada.',
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

    private function writeNew(array $job): void
    {
        $path = $this->path((string)$job['id']);
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $json, LOCK_EX) === false) throw new RuntimeException('No se pudo crear la tarea de extracción.');
        @chmod($path, 0600);
    }

    private function path(string $id): string
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new RuntimeException('Identificador de extracción inválido.');
        return $this->directory . '/' . $id . '.json';
    }
}
