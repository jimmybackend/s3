<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationShareDriveRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function receivedForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.ShareId, s.ResourceId, s.RemoteNodeId, s.Title, s.MediaType, s.Status, s.AccessUrl,
                    s.ExpiresAt, s.LocalFileId, s.LocalS3Key, s.ImportedAt, s.ImportedResourceUpdatedAt,
                    s.ImportedContentId, s.CreatedAt, s.UpdatedAt,
                    r.UpdatedAt AS ResourceUpdatedAt, r.ContentId AS ResourceContentId,
                    f.Found AS LocalFileFound,
                    (SELECT j.ImportId FROM FederationShareImportJobs j
                     WHERE j.UserId=s.UserId AND j.ShareId=s.ShareId
                     ORDER BY j.CreatedAt DESC, j.ImportId DESC LIMIT 1) AS ImportId,
                    (SELECT j.Status FROM FederationShareImportJobs j
                     WHERE j.UserId=s.UserId AND j.ShareId=s.ShareId
                     ORDER BY j.CreatedAt DESC, j.ImportId DESC LIMIT 1) AS ImportStatus,
                    (SELECT j.LastError FROM FederationShareImportJobs j
                     WHERE j.UserId=s.UserId AND j.ShareId=s.ShareId
                     ORDER BY j.CreatedAt DESC, j.ImportId DESC LIMIT 1) AS ImportError
             FROM FederationShares s
             LEFT JOIN FederatedResources r ON r.ResourceId=s.ResourceId AND r.Tombstoned=0
             LEFT JOIN FileS3 f ON f.id_=s.LocalFileId AND f.user_id_=s.UserId
             WHERE s.UserId=? AND s.Direction='received'
             ORDER BY s.UpdatedAt DESC, s.ShareId ASC LIMIT 200"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar Compartidos recibidos.', 500);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $this->publicRow($row);
        $stmt->close();
        return $rows;
    }

    public function receivedShare(int $userId, string $shareId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, r.UpdatedAt AS ResourceUpdatedAt, r.ContentId AS ResourceContentId, f.Found AS LocalFileFound
             FROM FederationShares s
             LEFT JOIN FederatedResources r ON r.ResourceId=s.ResourceId AND r.Tombstoned=0
             LEFT JOIN FileS3 f ON f.id_=s.LocalFileId AND f.user_id_=s.UserId
             WHERE s.UserId=? AND s.ShareId=? AND s.Direction='received' LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar el archivo compartido.', 500);
        $stmt->bind_param('is', $userId, $shareId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function queueImport(
        int $userId,
        string $shareId,
        string $resourceId,
        string $versionKey,
        ?string $resourceUpdatedAt
    ): array {
        $importId = 'fsi_' . bin2hex(random_bytes(24));
        $stmt = $this->db->prepare(
            "INSERT INTO FederationShareImportJobs
                (ImportId, UserId, ShareId, ResourceId, VersionKey, ResourceUpdatedAt, Status, NextAttemptAt)
             VALUES (?, ?, ?, ?, ?, ?, 'queued', UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
                Status=CASE
                  WHEN Status IN ('completed','processing') THEN Status
                  ELSE 'queued'
                END,
                NextAttemptAt=CASE
                  WHEN Status IN ('completed','processing') THEN NextAttemptAt
                  ELSE UTC_TIMESTAMP(6)
                END,
                LastError=CASE
                  WHEN Status IN ('completed','processing') THEN LastError
                  ELSE NULL
                END,
                UpdatedAt=UTC_TIMESTAMP(6)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la cola de Mi Drive.', 500);
        $stmt->bind_param('sissss', $importId, $userId, $shareId, $resourceId, $versionKey, $resourceUpdatedAt);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new FederationException('No se pudo encolar el archivo compartido.', 500);
        }
        $stmt->close();

        $job = $this->importByVersion($userId, $shareId, $versionKey);
        if ($job === null) throw new FederationException('No se pudo recuperar el trabajo de importación.', 500);
        return $job;
    }

    public function requeueCompleted(string $importId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationShareImportJobs
             SET Status='queued', Attempts=0, LastAttemptAt=NULL, NextAttemptAt=UTC_TIMESTAMP(6), LastError=NULL, LocalFileId=NULL, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE ImportId=? AND Status='completed' LIMIT 1"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $importId);
        $stmt->execute();
        $stmt->close();
    }

    public function dueImports(int $limit = 1): array
    {
        $limit = max(1, min(3, $limit));
        // Si el proceso murió a mitad de una copia, no dejamos el job bloqueado para siempre.
        $this->db->query(
            "UPDATE FederationShareImportJobs
             SET Status=IF(Attempts >= 5, 'failed', 'retry'),
                 NextAttemptAt=IF(Attempts >= 5, NULL, UTC_TIMESTAMP(6)),
                 LastError='La importación anterior se interrumpió y fue recuperada por el worker.',
                 UpdatedAt=UTC_TIMESTAMP(6)
             WHERE Status='processing'
               AND LastAttemptAt IS NOT NULL
               AND LastAttemptAt < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 HOUR)"
        );

        $sql = "SELECT ImportId, UserId, ShareId, ResourceId, VersionKey, ResourceUpdatedAt, Status, Attempts
                FROM FederationShareImportJobs
                WHERE Status IN ('queued','retry')
                  AND (NextAttemptAt IS NULL OR NextAttemptAt <= UTC_TIMESTAMP(6))
                ORDER BY COALESCE(NextAttemptAt, CreatedAt) ASC, CreatedAt ASC
                LIMIT {$limit}";
        $result = $this->db->query($sql);
        if (!$result) throw new FederationException('No se pudo consultar la cola de Mi Drive.', 500);
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    public function markProcessing(string $importId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationShareImportJobs
             SET Status='processing', Attempts=Attempts+1, LastAttemptAt=UTC_TIMESTAMP(6), NextAttemptAt=NULL, LastError=NULL
             WHERE ImportId=? AND Status IN ('queued','retry') LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $importId);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        return $claimed;
    }

    public function markImportCompleted(string $importId, int $localFileId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationShareImportJobs
             SET Status='completed', LocalFileId=?, NextAttemptAt=NULL, LastError=NULL, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE ImportId=? LIMIT 1"
        );
        if (!$stmt) return;
        $stmt->bind_param('is', $localFileId, $importId);
        $stmt->execute();
        $stmt->close();
    }

    public function markImportRetry(string $importId, string $error): string
    {
        $job = $this->importById($importId);
        if ($job === null) return 'failed';
        $attempts = max(1, (int)$job['Attempts']);
        $error = $this->cleanError($error);
        if ($attempts >= 5) {
            $stmt = $this->db->prepare(
                "UPDATE FederationShareImportJobs SET Status='failed', NextAttemptAt=NULL, LastError=?, UpdatedAt=UTC_TIMESTAMP(6) WHERE ImportId=? LIMIT 1"
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
            "UPDATE FederationShareImportJobs SET Status='retry', NextAttemptAt=?, LastError=?, UpdatedAt=UTC_TIMESTAMP(6) WHERE ImportId=? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('sss', $next, $error, $importId);
            $stmt->execute();
            $stmt->close();
        }
        return 'retry';
    }

    public function markImportFailed(string $importId, string $error): void
    {
        $error = $this->cleanError($error);
        $stmt = $this->db->prepare(
            "UPDATE FederationShareImportJobs SET Status='failed', NextAttemptAt=NULL, LastError=?, UpdatedAt=UTC_TIMESTAMP(6) WHERE ImportId=? LIMIT 1"
        );
        if (!$stmt) return;
        $stmt->bind_param('ss', $error, $importId);
        $stmt->execute();
        $stmt->close();
    }

    public function markImported(
        int $userId,
        string $shareId,
        int $fileId,
        string $s3Key,
        ?string $resourceUpdatedAt,
        ?string $contentId
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE FederationShares
             SET LocalFileId=?, LocalS3Key=?, ImportedAt=UTC_TIMESTAMP(6), ImportedResourceUpdatedAt=?, ImportedContentId=?
             WHERE UserId=? AND ShareId=? AND Direction='received' LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo vincular la copia con Compartidos.', 500);
        $stmt->bind_param('isssis', $fileId, $s3Key, $resourceUpdatedAt, $contentId, $userId, $shareId);
        if (!$stmt->execute() || $stmt->affected_rows < 1) {
            $stmt->close();
            throw new FederationException('No se pudo registrar la copia en Mi Drive.', 500);
        }
        $stmt->close();
    }

    public function attachProvenance(
        int $userId,
        int $fileId,
        string $shareId,
        string $resourceId,
        string $remoteNodeId
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
            'source' => 'share_received',
            'share_id' => $shareId,
            'resource_id' => $resourceId,
            'remote_node_id' => $remoteNodeId,
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

    private function importByVersion(int $userId, string $shareId, string $versionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ImportId, UserId, ShareId, ResourceId, VersionKey, ResourceUpdatedAt, Status, Attempts, LastError, LocalFileId, UpdatedAt '
            . 'FROM FederationShareImportJobs WHERE UserId=? AND ShareId=? AND VersionKey=? LIMIT 1'
        );
        if (!$stmt) return null;
        $stmt->bind_param('iss', $userId, $shareId, $versionKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function importById(string $importId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ImportId, Status, Attempts FROM FederationShareImportJobs WHERE ImportId=? LIMIT 1'
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
        $expiresAt = $row['ExpiresAt'] !== null ? (string)$row['ExpiresAt'] : null;
        $expired = $expiresAt !== null && strtotime($expiresAt) < time();
        $localFileId = isset($row['LocalFileId']) ? (int)$row['LocalFileId'] : 0;
        $localFileFound = (int)($row['LocalFileFound'] ?? 0) === 1;
        $imported = $localFileId > 0 && $localFileFound;
        $resourceUpdatedAt = is_string($row['ResourceUpdatedAt'] ?? null) ? (string)$row['ResourceUpdatedAt'] : null;
        $importedResourceUpdatedAt = is_string($row['ImportedResourceUpdatedAt'] ?? null)
            ? (string)$row['ImportedResourceUpdatedAt'] : null;
        $resourceContentId = is_string($row['ResourceContentId'] ?? null) && $row['ResourceContentId'] !== ''
            ? (string)$row['ResourceContentId'] : null;
        $importedContentId = is_string($row['ImportedContentId'] ?? null) && $row['ImportedContentId'] !== ''
            ? (string)$row['ImportedContentId'] : null;

        $updateAvailable = false;
        if ($imported && $resourceContentId !== null && $importedContentId !== null) {
            $updateAvailable = !hash_equals($importedContentId, $resourceContentId);
        } elseif ($imported && $resourceUpdatedAt !== null && $importedResourceUpdatedAt !== null) {
            $updateAvailable = strtotime($resourceUpdatedAt) > strtotime($importedResourceUpdatedAt);
        }

        return [
            'share_id' => (string)$row['ShareId'],
            'resource_id' => (string)$row['ResourceId'],
            'remote_node_id' => (string)$row['RemoteNodeId'],
            'title' => (string)$row['Title'],
            'media_type' => (string)$row['MediaType'],
            'status' => $expired && (string)$row['Status'] === 'active' ? 'expired' : (string)$row['Status'],
            'expires_at' => $expiresAt,
            'imported' => $imported,
            'local_file_id' => $imported ? $localFileId : null,
            'local_s3_key' => $imported && is_string($row['LocalS3Key'] ?? null) ? (string)$row['LocalS3Key'] : null,
            'imported_at' => $imported && is_string($row['ImportedAt'] ?? null) ? (string)$row['ImportedAt'] : null,
            'resource_updated_at' => $resourceUpdatedAt,
            'update_available' => $updateAvailable,
            'import_id' => is_string($row['ImportId'] ?? null) ? (string)$row['ImportId'] : null,
            'import_status' => is_string($row['ImportStatus'] ?? null) ? (string)$row['ImportStatus'] : null,
            'import_error' => is_string($row['ImportError'] ?? null) ? (string)$row['ImportError'] : null,
        ];
    }

    private function cleanError(string $error): string
    {
        $error = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error);
        return function_exists('mb_substr') ? mb_substr($error, 0, 512) : substr($error, 0, 512);
    }
}
