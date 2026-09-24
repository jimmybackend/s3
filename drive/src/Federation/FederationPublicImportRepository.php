<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationPublicImportRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function queue(int $userId, string $resourceId, string $versionKey): array
    {
        $importId = 'fpi_' . bin2hex(random_bytes(24));
        $stmt = $this->db->prepare(
            "INSERT INTO FederationPublicImportJobs
                (ImportId, UserId, ResourceId, VersionKey, Status, NextAttemptAt)
             VALUES (?, ?, ?, ?, 'queued', UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
                Status=CASE WHEN Status IN ('completed','processing') THEN Status ELSE 'queued' END,
                NextAttemptAt=CASE WHEN Status IN ('completed','processing') THEN NextAttemptAt ELSE UTC_TIMESTAMP(6) END,
                LastError=CASE WHEN Status IN ('completed','processing') THEN LastError ELSE NULL END,
                UpdatedAt=UTC_TIMESTAMP(6)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la importación pública.', 500);
        $stmt->bind_param('siss', $importId, $userId, $resourceId, $versionKey);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo encolar la importación pública: ' . $message, 500);
        }
        $stmt->close();

        $job = $this->byVersion($userId, $resourceId, $versionKey);
        if ($job === null) throw new FederationException('No se pudo recuperar la importación pública.', 500);
        return $job;
    }

    public function latest(int $userId, string $resourceId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT j.ImportId, j.UserId, j.ResourceId, j.VersionKey, j.Status, j.Attempts,
                    j.LastError, j.LocalFileId, j.SourcesUsed, j.CreatedAt, j.UpdatedAt,
                    f.Found AS LocalFileFound
             FROM FederationPublicImportJobs j
             LEFT JOIN FileS3 f ON f.id_=j.LocalFileId AND f.user_id_=j.UserId
             WHERE j.UserId=? AND j.ResourceId=?
             ORDER BY j.CreatedAt DESC, j.ImportId DESC LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar la importación pública.', 500);
        $stmt->bind_param('is', $userId, $resourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $this->publicRow($row) : null;
    }

    public function due(int $limit = 1): array
    {
        $limit = max(1, min(3, $limit));
        $this->db->query(
            "UPDATE FederationPublicImportJobs
             SET Status=IF(Attempts >= 5, 'failed', 'retry'),
                 NextAttemptAt=IF(Attempts >= 5, NULL, UTC_TIMESTAMP(6)),
                 LastError='La importación pública anterior se interrumpió y fue recuperada por el worker.',
                 UpdatedAt=UTC_TIMESTAMP(6)
             WHERE Status='processing'
               AND LastAttemptAt IS NOT NULL
               AND LastAttemptAt < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 HOUR)"
        );

        $sql = "SELECT ImportId, UserId, ResourceId, VersionKey, Status, Attempts
                FROM FederationPublicImportJobs
                WHERE Status IN ('queued','retry')
                  AND (NextAttemptAt IS NULL OR NextAttemptAt <= UTC_TIMESTAMP(6))
                ORDER BY COALESCE(NextAttemptAt, CreatedAt) ASC, CreatedAt ASC
                LIMIT {$limit}";
        $result = $this->db->query($sql);
        if (!$result) throw new FederationException('No se pudo consultar la cola pública.', 500);
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    public function markProcessing(string $importId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationPublicImportJobs
             SET Status='processing', Attempts=Attempts+1, LastAttemptAt=UTC_TIMESTAMP(6),
                 NextAttemptAt=NULL, LastError=NULL, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE ImportId=? AND Status IN ('queued','retry') LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $importId);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        return $claimed;
    }

    public function markCompleted(string $importId, int $localFileId, int $sourcesUsed): void
    {
        $sourcesUsed = max(1, min(FederationMultiSourceDownloader::MAX_SOURCES, $sourcesUsed));
        $stmt = $this->db->prepare(
            "UPDATE FederationPublicImportJobs
             SET Status='completed', LocalFileId=?, SourcesUsed=?, NextAttemptAt=NULL,
                 LastError=NULL, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE ImportId=? LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo cerrar la importación pública.', 500);
        $stmt->bind_param('iis', $localFileId, $sourcesUsed, $importId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo cerrar la importación pública: ' . $message, 500);
        }
        $stmt->close();
    }

    public function markRetry(string $importId, string $error): string
    {
        $job = $this->byId($importId);
        if ($job === null) return 'failed';
        $attempts = max(1, (int)$job['Attempts']);
        $error = $this->cleanError($error);

        if ($attempts >= 5) {
            $stmt = $this->db->prepare(
                "UPDATE FederationPublicImportJobs
                 SET Status='failed', NextAttemptAt=NULL, LastError=?, UpdatedAt=UTC_TIMESTAMP(6)
                 WHERE ImportId=? LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('ss', $error, $importId);
                $stmt->execute();
                $stmt->close();
            }
            return 'failed';
        }

        $delay = min(3600, 30 * (2 ** min(7, $attempts - 1)));
        $next = gmdate('Y-m-d H:i:s', time() + $delay);
        $stmt = $this->db->prepare(
            "UPDATE FederationPublicImportJobs
             SET Status='retry', NextAttemptAt=?, LastError=?, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE ImportId=? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('sss', $next, $error, $importId);
            $stmt->execute();
            $stmt->close();
        }
        return 'retry';
    }

    public function attachProvenance(
        int $userId,
        int $fileId,
        string $resourceId,
        string $originNodeId,
        int $sourcesUsed
    ): void {
        $stmt = $this->db->prepare('SELECT Metadatos FROM FileS3 WHERE id_=? AND user_id_=? LIMIT 1');
        if (!$stmt) return;
        $stmt->bind_param('ii', $fileId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $metadata = [];
        if (is_array($row) && is_string($row['Metadatos'] ?? null) && trim((string)$row['Metadatos']) !== '') {
            $decoded = json_decode((string)$row['Metadatos'], true);
            if (is_array($decoded) && !array_is_list($decoded)) $metadata = $decoded;
        }
        $metadata['federationcloud'] = [
            'source' => 'public_resource',
            'resource_id' => $resourceId,
            'origin_node_id' => $originNodeId,
            'sources_used' => max(1, $sourcesUsed),
            'imported_at' => gmdate(DATE_ATOM),
        ];
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return;

        $update = $this->db->prepare('UPDATE FileS3 SET Metadatos=? WHERE id_=? AND user_id_=? LIMIT 1');
        if (!$update) return;
        $update->bind_param('sii', $json, $fileId, $userId);
        $update->execute();
        $update->close();
    }

    private function byVersion(int $userId, string $resourceId, string $versionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ImportId, UserId, ResourceId, VersionKey, Status, Attempts, LastError, LocalFileId, SourcesUsed, UpdatedAt '
            . 'FROM FederationPublicImportJobs WHERE UserId=? AND ResourceId=? AND VersionKey=? LIMIT 1'
        );
        if (!$stmt) return null;
        $stmt->bind_param('iss', $userId, $resourceId, $versionKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $this->publicRow($row) : null;
    }

    private function byId(string $importId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ImportId, Status, Attempts FROM FederationPublicImportJobs WHERE ImportId=? LIMIT 1'
        );
        if (!$stmt) return null;
        $stmt->bind_param('s', $importId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function publicRow(array $row): array
    {
        return [
            'import_id' => (string)$row['ImportId'],
            'resource_id' => (string)$row['ResourceId'],
            'status' => (string)$row['Status'],
            'attempts' => (int)($row['Attempts'] ?? 0),
            'last_error' => is_string($row['LastError'] ?? null) ? (string)$row['LastError'] : null,
            'local_file_id' => isset($row['LocalFileId']) && (int)$row['LocalFileId'] > 0 ? (int)$row['LocalFileId'] : null,
            'local_file_found' => !isset($row['LocalFileFound']) || (int)$row['LocalFileFound'] === 1,
            'sources_used' => (int)($row['SourcesUsed'] ?? 0),
            'updated_at' => (string)($row['UpdatedAt'] ?? ''),
        ];
    }

    private function cleanError(string $error): string
    {
        $error = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error);
        return function_exists('mb_substr') ? mb_substr($error, 0, 512) : substr($error, 0, 512);
    }
}
