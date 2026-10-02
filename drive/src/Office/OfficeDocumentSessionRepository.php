<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use mysqli;
use RuntimeException;

final class OfficeDocumentSessionRepository
{
    public function __construct(private mysqli $db)
    {
        $this->ensureSchema();
    }

    /**
     * @return array{session_id:string,control_token:string}
     */
    public function create(
        int $userId,
        int $fileId,
        string $instanceId,
        string $originalKey,
        string $visibleName
    ): array {
        if (
            $userId <= 0
            || $fileId <= 0
            || !preg_match('/^i-[0-9a-f]{8,17}$/i', $instanceId)
            || trim($originalKey) === ''
            || trim($visibleName) === ''
        ) {
            throw new RuntimeException('Datos inválidos para la sesión documental de Office.');
        }

        $sessionId = bin2hex(random_bytes(16));
        $controlToken = bin2hex(random_bytes(32));
        $controlHash = hash('sha256', $controlToken);
        $status = 'preparing';

        $stmt = $this->db->prepare(
            'INSERT INTO OfficeDocumentSessions '
            . '(SessionId,ControlTokenHash,UserId,FileId,InstanceId,OriginalKey,VisibleName,Status,CreatedAt,UpdatedAt) '
            . 'VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la sesión documental de Office.');
        }
        $stmt->bind_param(
            'ssiissss',
            $sessionId,
            $controlHash,
            $userId,
            $fileId,
            $instanceId,
            $originalKey,
            $visibleName,
            $status
        );
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo crear la sesión documental de Office: ' . $error);
        }
        $stmt->close();

        return ['session_id' => $sessionId, 'control_token' => $controlToken];
    }

    public function requireAuthorized(string $sessionId, string $controlToken): array
    {
        $sessionId = strtolower(trim($sessionId));
        $controlToken = strtolower(trim($controlToken));
        if (
            !preg_match('/^[a-f0-9]{32}$/', $sessionId)
            || !preg_match('/^[a-f0-9]{64}$/', $controlToken)
        ) {
            throw new RuntimeException('Credenciales de sesión documental inválidas.');
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM OfficeDocumentSessions '
            . "WHERE SessionId=? AND Status IN ('preparing','ready','syncing','conflict') LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar la sesión documental.');
        }
        $stmt->bind_param('s', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();

        if (!is_array($row)) {
            throw new RuntimeException('La sesión documental no existe o ya terminó.');
        }
        $stored = (string)($row['ControlTokenHash'] ?? '');
        if ($stored === '' || !hash_equals($stored, hash('sha256', $controlToken))) {
            throw new RuntimeException('La sesión documental no está autorizada.');
        }

        return $this->normalize($row);
    }

    public function markPrepared(
        string $sessionId,
        string $workspaceRelative,
        string $etag,
        int $mtime,
        int $size
    ): void {
        $status = 'ready';
        $stmt = $this->db->prepare(
            'UPDATE OfficeDocumentSessions '
            . 'SET WorkspaceRelative=?,ExpectedETag=?,LastWorkspaceMtime=?,LastWorkspaceSize=?,'
            . 'Status=?,LastSyncedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP() '
            . 'WHERE SessionId=?'
        );
        if (!$stmt) throw new RuntimeException('No se pudo marcar el documento como preparado.');
        $stmt->bind_param('ssiiss', $workspaceRelative, $etag, $mtime, $size, $status, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    public function markSyncing(string $sessionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE OfficeDocumentSessions SET Status='syncing',UpdatedAt=UTC_TIMESTAMP() WHERE SessionId=?"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    public function recoverSyncFailure(string $sessionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE OfficeDocumentSessions SET Status='ready',UpdatedAt=UTC_TIMESTAMP() "
            . "WHERE SessionId=? AND Status='syncing'"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    public function markSynced(
        string $sessionId,
        string $etag,
        int $mtime,
        int $size
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE OfficeDocumentSessions "
            . "SET ExpectedETag=?,LastWorkspaceMtime=?,LastWorkspaceSize=?,Status='ready',"
            . "LastSyncedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP() WHERE SessionId=?"
        );
        if (!$stmt) throw new RuntimeException('No se pudo registrar el guardado Office.');
        $stmt->bind_param('siis', $etag, $mtime, $size, $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    public function adoptConflict(
        string $sessionId,
        int $conflictFileId,
        string $conflictKey,
        string $conflictName,
        string $etag,
        int $mtime,
        int $size
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE OfficeDocumentSessions "
            . "SET FileId=?,OriginalKey=?,VisibleName=?,ExpectedETag=?,"
            . "LastWorkspaceMtime=?,LastWorkspaceSize=?,Status='ready',ConflictFileId=?,"
            . "LastSyncedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP() WHERE SessionId=?"
        );
        if (!$stmt) throw new RuntimeException('No se pudo registrar el conflicto Office.');
        $stmt->bind_param(
            'isssiiis',
            $conflictFileId,
            $conflictKey,
            $conflictName,
            $etag,
            $mtime,
            $size,
            $conflictFileId,
            $sessionId
        );
        $stmt->execute();
        $stmt->close();
    }

    public function markFailed(string $sessionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE OfficeDocumentSessions SET Status='failed',ClosedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP() WHERE SessionId=?"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    public function markClosed(string $sessionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE OfficeDocumentSessions SET Status='closed',ClosedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP() "
            . "WHERE SessionId=?"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<int,array<string,mixed>> */
    public function activeCandidates(string $instanceId): array
    {
        if (!preg_match('/^i-[0-9a-f]{8,17}$/i', $instanceId)) {
            throw new RuntimeException('Instancia Office inválida.');
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM OfficeDocumentSessions
             WHERE InstanceId=? AND Status IN ('preparing','ready','syncing','conflict')
             ORDER BY id_ ASC"
        );
        if (!$stmt) throw new RuntimeException('No se pudieron consultar sesiones Office activas.');
        $stmt->bind_param('s', $instanceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $this->normalize($row);
        }
        if ($result) $result->free();
        $stmt->close();
        return $rows;
    }

    public function markClosedIfUnchanged(string $sessionId, int $mtime, int $size): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE OfficeDocumentSessions
             SET Status='closed',ClosedAt=UTC_TIMESTAMP(),UpdatedAt=UTC_TIMESTAMP()
             WHERE SessionId=? AND Status='ready'
               AND LastSyncedAt IS NOT NULL
               AND LastWorkspaceMtime=? AND LastWorkspaceSize=?"
        );
        if (!$stmt) return false;
        $stmt->bind_param('sii', $sessionId, $mtime, $size);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        return $changed;
    }

    /** @return array<int,array<string,mixed>> */
    public function cleanupCandidates(int $olderThanMinutes = 30): array
    {
        $olderThanMinutes = max(10, min(1440, $olderThanMinutes));
        $sql = "SELECT SessionId,WorkspaceRelative,LastWorkspaceMtime,LastWorkspaceSize FROM OfficeDocumentSessions "
            . "WHERE Status='closed' AND ClosedAt IS NOT NULL "
            . "AND ClosedAt < (UTC_TIMESTAMP() - INTERVAL {$olderThanMinutes} MINUTE) LIMIT 50";
        $result = $this->db->query($sql);
        if (!$result) return [];

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'session_id' => (string)($row['SessionId'] ?? ''),
                'workspace_relative' => (string)($row['WorkspaceRelative'] ?? ''),
                'last_workspace_mtime' => (int)($row['LastWorkspaceMtime'] ?? 0),
                'last_workspace_size' => (int)($row['LastWorkspaceSize'] ?? 0),
            ];
        }
        return $rows;
    }

    public function deleteClosed(string $sessionId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM OfficeDocumentSessions WHERE SessionId=? AND Status IN ('closed','failed')"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    private function normalize(array $row): array
    {
        return [
            'session_id' => (string)($row['SessionId'] ?? ''),
            'user_id' => (int)($row['UserId'] ?? 0),
            'file_id' => (int)($row['FileId'] ?? 0),
            'instance_id' => (string)($row['InstanceId'] ?? ''),
            'original_key' => (string)($row['OriginalKey'] ?? ''),
            'visible_name' => (string)($row['VisibleName'] ?? ''),
            'workspace_relative' => (string)($row['WorkspaceRelative'] ?? ''),
            'expected_etag' => (string)($row['ExpectedETag'] ?? ''),
            'last_workspace_mtime' => (int)($row['LastWorkspaceMtime'] ?? 0),
            'last_workspace_size' => (int)($row['LastWorkspaceSize'] ?? 0),
            'status' => (string)($row['Status'] ?? ''),
            'conflict_file_id' => isset($row['ConflictFileId']) && $row['ConflictFileId'] !== null
                ? (int)$row['ConflictFileId']
                : null,
            'last_synced_at' => (string)($row['LastSyncedAt'] ?? ''),
            'created_at' => (string)($row['CreatedAt'] ?? ''),
            'updated_at' => (string)($row['UpdatedAt'] ?? ''),
            'closed_at' => (string)($row['ClosedAt'] ?? ''),
        ];
    }

    private function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS OfficeDocumentSessions (
  id_ BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  SessionId CHAR(32) NOT NULL,
  ControlTokenHash CHAR(64) NOT NULL,
  UserId INT NOT NULL,
  FileId BIGINT UNSIGNED NOT NULL,
  InstanceId VARCHAR(32) NOT NULL,
  OriginalKey VARCHAR(1024) NOT NULL,
  VisibleName VARCHAR(255) NOT NULL,
  WorkspaceRelative VARCHAR(512) NULL,
  ExpectedETag VARCHAR(255) NULL,
  LastWorkspaceMtime BIGINT NOT NULL DEFAULT 0,
  LastWorkspaceSize BIGINT NOT NULL DEFAULT 0,
  Status VARCHAR(20) NOT NULL,
  ConflictFileId BIGINT UNSIGNED NULL,
  LastSyncedAt DATETIME NULL,
  CreatedAt DATETIME NOT NULL,
  UpdatedAt DATETIME NOT NULL,
  ClosedAt DATETIME NULL,
  PRIMARY KEY (id_),
  UNIQUE KEY uq_office_document_session (SessionId),
  KEY idx_office_document_user_status (UserId,Status,id_),
  KEY idx_office_document_file_status (FileId,Status,id_)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        if (!$this->db->query($sql)) {
            throw new RuntimeException('No se pudo preparar OfficeDocumentSessions: ' . $this->db->error);
        }
    }
}
