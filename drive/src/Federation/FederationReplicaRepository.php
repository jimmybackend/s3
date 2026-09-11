<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationReplicaRepository
{
    public function __construct(private mysqli $db) {}

    public function queueOutgoing(array $job): void
    {
        $offerId = (string)$job['offer_id'];
        $resourceId = (string)$job['resource_id'];
        $userId = (int)$job['user_id'];
        $nodeId = (string)$job['remote_node_id'];
        $url = (string)$job['remote_federation_url'];
        $role = (string)$job['role'];
        $storageRef = (string)$job['storage_ref'];
        $contentId = (string)$job['content_id'];
        $size = (int)$job['size_bytes'];
        $title = (string)$job['title'];
        $mediaType = (string)$job['media_type'];

        $stmt = $this->db->prepare(
            "INSERT INTO FederationReplicaJobs
             (OfferId, Direction, ResourceId, LocalUserId, RemoteNodeId, RemoteFederationUrl, Role, Status,
              SourceStorageRef, ContentId, SizeBytes, Title, MediaType, NextAttemptAt, ExpiresAt)
             VALUES (?, 'outgoing', ?, ?, ?, ?, ?, 'queued', ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 7 DAY))
             ON DUPLICATE KEY UPDATE
               RemoteFederationUrl=VALUES(RemoteFederationUrl), Role=VALUES(Role), SourceStorageRef=VALUES(SourceStorageRef),
               ContentId=VALUES(ContentId), SizeBytes=VALUES(SizeBytes), Title=VALUES(Title), MediaType=VALUES(MediaType),
               Status=IF(Status='active','active','queued'), NextAttemptAt=IF(Status='active',NextAttemptAt,UTC_TIMESTAMP(6)),
               LastError=IF(Status='active',LastError,NULL), ExpiresAt=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 7 DAY)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar cola de réplica.', 500);
        $stmt->bind_param('ssisssssiss', $offerId, $resourceId, $userId, $nodeId, $url, $role, $storageRef, $contentId, $size, $title, $mediaType);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo encolar réplica: ' . $message, 500);
        }
        $stmt->close();
    }

    public function storeIncoming(array $offer, string $originFederationUrl): string
    {
        $json = FederationCodec::canonicalJson($offer);
        $offerId = (string)$offer['offer_id'];
        $resourceId = (string)$offer['resource_id'];
        $originNodeId = (string)$offer['origin_node_id'];
        $role = (string)$offer['role'];
        $contentId = (string)$offer['content_id'];
        $size = (int)$offer['size_bytes'];
        $title = (string)$offer['title'];
        $mediaType = (string)$offer['media_type'];
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime((string)$offer['expires_at']) ?: time());

        $existing = $this->job($offerId);
        if ($existing !== null && (string)$existing['Status'] === 'active') return 'active';

        $stmt = $this->db->prepare(
            "INSERT INTO FederationReplicaJobs
             (OfferId, Direction, ResourceId, RemoteNodeId, RemoteFederationUrl, Role, Status, OfferJson,
              ContentId, SizeBytes, Title, MediaType, NextAttemptAt, ExpiresAt)
             VALUES (?, 'incoming', ?, ?, ?, ?, 'queued', ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?)
             ON DUPLICATE KEY UPDATE OfferJson=VALUES(OfferJson), RemoteFederationUrl=VALUES(RemoteFederationUrl),
               Role=VALUES(Role), ContentId=VALUES(ContentId), SizeBytes=VALUES(SizeBytes), Title=VALUES(Title),
               MediaType=VALUES(MediaType), Status=IF(Status='active','active','queued'),
               NextAttemptAt=IF(Status='active',NextAttemptAt,UTC_TIMESTAMP(6)), LastError=IF(Status='active',LastError,NULL),
               ExpiresAt=VALUES(ExpiresAt)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar recepción de réplica.', 500);
        $stmt->bind_param('sssssssisss', $offerId, $resourceId, $originNodeId, $originFederationUrl, $role, $json, $contentId, $size, $title, $mediaType, $expiresAt);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo guardar oferta de réplica: ' . $message, 500);
        }
        $stmt->close();
        return 'queued';
    }

    public function due(string $direction, int $limit): array
    {
        if (!in_array($direction, ['outgoing','incoming'], true)) return [];
        $limit = max(1, min(10, $limit));
        $statuses = $direction === 'outgoing' ? "'queued','retry','offered'" : "'queued','retry','stored'";
        $sql = "SELECT * FROM FederationReplicaJobs
                WHERE Direction=? AND Status IN ({$statuses})
                  AND (NextAttemptAt IS NULL OR NextAttemptAt <= UTC_TIMESTAMP(6))
                  AND (ExpiresAt IS NULL OR ExpiresAt > UTC_TIMESTAMP(6))
                ORDER BY COALESCE(NextAttemptAt, CreatedAt) ASC, CreatedAt ASC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new FederationException('No se pudo consultar cola de réplicas.', 500);
        $stmt->bind_param('s', $direction);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    public function markOffered(string $offerId): void
    {
        $this->updateAttempt($offerId, 'offered', null, 300);
    }

    public function markTransferring(string $offerId): void
    {
        $stmt = $this->db->prepare("UPDATE FederationReplicaJobs SET Status='transferring', Attempts=Attempts+1, LastError=NULL, UpdatedAt=UTC_TIMESTAMP(6) WHERE OfferId=? LIMIT 1");
        if (!$stmt) return;
        $stmt->bind_param('s', $offerId);
        $stmt->execute();
        $stmt->close();
    }

    public function markStored(string $offerId, string $s3Key): void
    {
        $stmt = $this->db->prepare("UPDATE FederationReplicaJobs SET Status='stored', LocalS3Key=?, LastError=NULL, NextAttemptAt=UTC_TIMESTAMP(6), UpdatedAt=UTC_TIMESTAMP(6) WHERE OfferId=? LIMIT 1");
        if (!$stmt) throw new FederationException('No se pudo marcar réplica almacenada.', 500);
        $stmt->bind_param('ss', $s3Key, $offerId);
        $stmt->execute();
        $stmt->close();
    }

    public function markActive(string $offerId): void
    {
        $stmt = $this->db->prepare("UPDATE FederationReplicaJobs SET Status='active', LastError=NULL, NextAttemptAt=NULL, UpdatedAt=UTC_TIMESTAMP(6) WHERE OfferId=? LIMIT 1");
        if (!$stmt) return;
        $stmt->bind_param('s', $offerId);
        $stmt->execute();
        $stmt->close();
    }

    public function markRetry(string $offerId, string $message): void
    {
        $row = $this->job($offerId);
        $attempts = max(0, (int)($row['Attempts'] ?? 0)) + 1;
        $delay = min(3600, 30 * (2 ** min(7, $attempts - 1)));
        $this->updateAttempt($offerId, 'retry', substr($message, 0, 512), $delay);
    }

    public function expireOld(): int
    {
        $stmt = $this->db->prepare("UPDATE FederationReplicaJobs SET Status='expired', UpdatedAt=UTC_TIMESTAMP(6) WHERE Status NOT IN ('active','expired','failed') AND ExpiresAt IS NOT NULL AND ExpiresAt <= UTC_TIMESTAMP(6)");
        if (!$stmt) return 0;
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return max(0, $count);
    }

    public function upsertObject(array $row): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO FederationReplicaObjects (ResourceId, OriginNodeId, Role, S3Key, ContentId, SizeBytes, Status, VerifiedAt)
             VALUES (?, ?, ?, ?, ?, ?, 'stored', UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE OriginNodeId=VALUES(OriginNodeId), Role=VALUES(Role), S3Key=VALUES(S3Key),
               ContentId=VALUES(ContentId), SizeBytes=VALUES(SizeBytes), Status='stored', VerifiedAt=UTC_TIMESTAMP(6), UpdatedAt=UTC_TIMESTAMP(6)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar objeto réplica.', 500);
        $resourceId = (string)$row['resource_id'];
        $originNodeId = (string)$row['origin_node_id'];
        $role = (string)$row['role'];
        $s3Key = (string)$row['s3_key'];
        $contentId = (string)$row['content_id'];
        $size = (int)$row['size_bytes'];
        $stmt->bind_param('sssssi', $resourceId, $originNodeId, $role, $s3Key, $contentId, $size);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo registrar objeto réplica: ' . $message, 500);
        }
        $stmt->close();
    }

    public function activateObject(string $resourceId): void
    {
        $stmt = $this->db->prepare("UPDATE FederationReplicaObjects SET Status='active', UpdatedAt=UTC_TIMESTAMP(6) WHERE ResourceId=? LIMIT 1");
        if (!$stmt) return;
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $stmt->close();
    }

    public function object(string $resourceId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM FederationReplicaObjects WHERE ResourceId=? LIMIT 1');
        if (!$stmt) throw new FederationException('No se pudo consultar réplica local.', 500);
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function job(string $offerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM FederationReplicaJobs WHERE OfferId=? LIMIT 1');
        if (!$stmt) throw new FederationException('No se pudo consultar trabajo de réplica.', 500);
        $stmt->bind_param('s', $offerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function jobsForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare("SELECT OfferId, Direction, ResourceId, RemoteNodeId, Role, Status, Attempts, NextAttemptAt, LastError, CreatedAt, UpdatedAt FROM FederationReplicaJobs WHERE LocalUserId=? ORDER BY UpdatedAt DESC LIMIT {$limit}");
        if (!$stmt) throw new FederationException('No se pudieron listar trabajos de réplica.', 500);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private function updateAttempt(string $offerId, string $status, ?string $error, int $delaySeconds): void
    {
        $next = gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds));
        $stmt = $this->db->prepare("UPDATE FederationReplicaJobs SET Status=?, Attempts=Attempts+1, LastError=?, NextAttemptAt=?, UpdatedAt=UTC_TIMESTAMP(6) WHERE OfferId=? LIMIT 1");
        if (!$stmt) return;
        $stmt->bind_param('ssss', $status, $error, $next, $offerId);
        $stmt->execute();
        $stmt->close();
    }
}
