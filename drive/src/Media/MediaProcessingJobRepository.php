<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Media;

use mysqli;
use RuntimeException;

final class MediaProcessingJobRepository
{
    public function __construct(private mysqli $db)
    {
        $this->ensureSchema();
    }

    public function enqueue(
        int $userId,
        array $source,
        string $operation,
        int $parts,
        int $overlapBefore,
        int $overlapAfter
    ): array {
        $jobId = bin2hex(random_bytes(16));
        $sourceFileId = (int)($source['id_'] ?? 0);
        $sourceKey = (string)($source['_key'] ?? '');
        $sourceName = (string)($source['Nombre'] ?? basename($sourceKey));
        $sourceRoute = (string)($source['Ruta'] ?? '');
        $sourceBytes = max(0, (int)($source['Tamano'] ?? 0));
        $status = 'queued';

        $stmt = $this->db->prepare(
            'INSERT INTO MediaProcessingJobs '
            . '(JobId,user_id_,SourceFileId,SourceKey,SourceName,SourceRoute,SourceBytes,Operation,Parts,OverlapBefore,OverlapAfter,Status,Progress,CreatedAt,UpdatedAt) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la tarea multimedia: ' . $this->db->error);
        }
        $stmt->bind_param(
            'siisssisiiis',
            $jobId,
            $userId,
            $sourceFileId,
            $sourceKey,
            $sourceName,
            $sourceRoute,
            $sourceBytes,
            $operation,
            $parts,
            $overlapBefore,
            $overlapAfter,
            $status
        );
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo crear la tarea multimedia: ' . $error);
        }
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $this->requireOwned($userId, $jobId, $id);
    }

    public function recentForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            'SELECT * FROM MediaProcessingJobs WHERE user_id_=? '
            . 'ORDER BY id_ DESC LIMIT ' . $limit
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudieron consultar tareas multimedia.');
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
        return array_map([$this, 'normalize'], $rows);
    }

    public function latestDependencyFailure(int $hours = 24): ?array
    {
        $hours = max(1, min(168, $hours));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));

        $stmt = $this->db->prepare(
            "SELECT WorkerId,ErrorMessage,UpdatedAt
             FROM MediaProcessingJobs
             WHERE Status='failed'
               AND ErrorMessage LIKE '[DEPENDENCY_MISSING]%'
               AND UpdatedAt >= ?
             ORDER BY UpdatedAt DESC,id_ DESC
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar el estado del nodo multimedia.');
        }
        $stmt->bind_param('s', $cutoff);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();

        if (!is_array($row)) {
            return null;
        }

        return [
            'worker_id' => (string)($row['WorkerId'] ?? ''),
            'message' => (string)($row['ErrorMessage'] ?? ''),
            'updated_at' => (string)($row['UpdatedAt'] ?? ''),
        ];
    }

    public function claimNext(string $workerId): ?array
    {
        $workerId = trim($workerId);
        if ($workerId === '') {
            throw new RuntimeException('Identificador de worker inválido.');
        }

        $stmt = $this->db->prepare(
            "UPDATE MediaProcessingJobs
             SET Status='running', WorkerId=?, StartedAt=COALESCE(StartedAt,UTC_TIMESTAMP()), UpdatedAt=UTC_TIMESTAMP()
             WHERE Status='queued'
             ORDER BY id_ ASC
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo reclamar una tarea multimedia.');
        }
        $stmt->bind_param('s', $workerId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 1) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM MediaProcessingJobs
             WHERE WorkerId=? AND Status='running'
             ORDER BY StartedAt DESC,id_ DESC LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo recuperar la tarea reclamada.');
        }
        $stmt->bind_param('s', $workerId);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return $row ? $this->normalize($row) : null;
    }

    public function progress(string $jobId, int $percent): void
    {
        $percent = max(0, min(99, $percent));
        $stmt = $this->db->prepare(
            "UPDATE MediaProcessingJobs SET Progress=?,UpdatedAt=UTC_TIMESTAMP()
             WHERE JobId=? AND Status='running'"
        );
        if (!$stmt) return;
        $stmt->bind_param('is', $percent, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    public function complete(string $jobId, array $outputs): void
    {
        $json = json_encode($outputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudieron serializar las salidas multimedia.');
        }
        $stmt = $this->db->prepare(
            "UPDATE MediaProcessingJobs
             SET Status='completed',Progress=100,OutputsJson=?,ErrorMessage=NULL,CompletedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP()
             WHERE JobId=?"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo finalizar la tarea multimedia.');
        }
        $stmt->bind_param('ss', $json, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    public function fail(string $jobId, string $message): void
    {
        $message = mb_substr(trim($message), 0, 4000);
        $stmt = $this->db->prepare(
            "UPDATE MediaProcessingJobs
             SET Status='failed',ErrorMessage=?,CompletedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP()
             WHERE JobId=?"
        );
        if (!$stmt) return;
        $stmt->bind_param('ss', $message, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    private function requireOwned(int $userId, string $jobId, int $id = 0): array
    {
        $sql = 'SELECT * FROM MediaProcessingJobs WHERE user_id_=? AND '
            . ($id > 0 ? 'id_=?' : 'JobId=?') . ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('No se pudo recuperar la tarea multimedia.');
        if ($id > 0) {
            $stmt->bind_param('ii', $userId, $id);
        } else {
            $stmt->bind_param('is', $userId, $jobId);
        }
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        if (!$row) throw new RuntimeException('Tarea multimedia no encontrada.');
        return $this->normalize($row);
    }

    private function normalize(array $row): array
    {
        $outputs = [];
        $raw = (string)($row['OutputsJson'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $outputs = $decoded;
        }
        return [
            'id' => (int)($row['id_'] ?? 0),
            'job_id' => (string)($row['JobId'] ?? ''),
            'user_id' => (int)($row['user_id_'] ?? 0),
            'source_file_id' => (int)($row['SourceFileId'] ?? 0),
            'source_key' => (string)($row['SourceKey'] ?? ''),
            'source_name' => (string)($row['SourceName'] ?? ''),
            'source_route' => (string)($row['SourceRoute'] ?? ''),
            'source_bytes' => (int)($row['SourceBytes'] ?? 0),
            'operation' => (string)($row['Operation'] ?? ''),
            'parts' => (int)($row['Parts'] ?? 0),
            'overlap_before' => (int)($row['OverlapBefore'] ?? 0),
            'overlap_after' => (int)($row['OverlapAfter'] ?? 0),
            'status' => (string)($row['Status'] ?? 'queued'),
            'progress' => (int)($row['Progress'] ?? 0),
            'worker_id' => (string)($row['WorkerId'] ?? ''),
            'outputs' => $outputs,
            'error' => (string)($row['ErrorMessage'] ?? ''),
            'created_at' => (string)($row['CreatedAt'] ?? ''),
            'updated_at' => (string)($row['UpdatedAt'] ?? ''),
            'started_at' => (string)($row['StartedAt'] ?? ''),
            'completed_at' => (string)($row['CompletedAt'] ?? ''),
        ];
    }

    private function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS MediaProcessingJobs (
  id_ BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  JobId CHAR(32) NOT NULL,
  user_id_ INT NOT NULL,
  SourceFileId INT NOT NULL,
  SourceKey VARCHAR(1024) NOT NULL,
  SourceName VARCHAR(512) NOT NULL,
  SourceRoute VARCHAR(1024) NOT NULL DEFAULT '',
  SourceBytes BIGINT NOT NULL DEFAULT 0,
  Operation VARCHAR(32) NOT NULL,
  Parts SMALLINT NOT NULL DEFAULT 1,
  OverlapBefore SMALLINT NOT NULL DEFAULT 0,
  OverlapAfter SMALLINT NOT NULL DEFAULT 0,
  Status VARCHAR(20) NOT NULL DEFAULT 'queued',
  Progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  WorkerId VARCHAR(191) NULL,
  OutputsJson LONGTEXT NULL,
  ErrorMessage TEXT NULL,
  CreatedAt DATETIME NOT NULL,
  UpdatedAt DATETIME NOT NULL,
  StartedAt DATETIME NULL,
  CompletedAt DATETIME NULL,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_media_job_id (JobId),
  KEY idx_media_user_created (user_id_, CreatedAt),
  KEY idx_media_status_id (Status, id_)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        if (!$this->db->query($sql)) {
            throw new RuntimeException('No se pudo preparar la cola multimedia: ' . $this->db->error);
        }
    }
}
