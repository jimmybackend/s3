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
                    s.CreatedAt, s.UpdatedAt,
                    r.UpdatedAt AS ResourceUpdatedAt,
                    f.Found AS LocalFileFound
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
            "SELECT s.*, r.UpdatedAt AS ResourceUpdatedAt, f.Found AS LocalFileFound
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

    public function markImported(
        int $userId,
        string $shareId,
        int $fileId,
        string $s3Key,
        ?string $resourceUpdatedAt
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE FederationShares
             SET LocalFileId=?, LocalS3Key=?, ImportedAt=UTC_TIMESTAMP(6), ImportedResourceUpdatedAt=?
             WHERE UserId=? AND ShareId=? AND Direction='received' LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo vincular la copia con Compartidos.', 500);
        $stmt->bind_param('issis', $fileId, $s3Key, $resourceUpdatedAt, $userId, $shareId);
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
        $updateAvailable = $imported
            && $resourceUpdatedAt !== null
            && $importedResourceUpdatedAt !== null
            && strtotime($resourceUpdatedAt) > strtotime($importedResourceUpdatedAt);

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
        ];
    }
}
