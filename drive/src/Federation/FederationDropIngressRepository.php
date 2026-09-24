<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationDropIngressRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function eligibleProviders(string $commerceNodeId, int $sizeBytes, int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->prepare(
            "SELECT p.NodeId, p.DomainName, p.CommissionBps, p.RegionName, p.GuaranteeMode,
                    n.PublicUrl, n.FederationUrl, n.LastSeen
             FROM FederationCommercialProviders p
             INNER JOIN FederationNodes n ON n.NodeId=p.NodeId
             WHERE p.Status='active'
               AND n.Status='active'
               AND n.LastSeen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
               AND p.NodeId <> ?
               AND (p.CapacityBytes=0 OR p.CapacityBytes >= ?)
               AND (p.MaxFileBytes=0 OR p.MaxFileBytes >= ?)
             ORDER BY p.ApprovedAt ASC, p.NodeId ASC
             LIMIT {$limit}"
        );
        if (!$stmt) throw new FederationException('No se pudieron consultar nodos ingress FederationDrop.', 500);
        $stmt->bind_param('sii', $commerceNodeId, $sizeBytes, $sizeBytes);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudieron consultar nodos ingress: ' . $message, 500);
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'node_id' => (string)$row['NodeId'],
                'domain' => (string)$row['DomainName'],
                'commission_bps' => (int)$row['CommissionBps'],
                'region' => is_string($row['RegionName'] ?? null) ? (string)$row['RegionName'] : null,
                'guarantee_mode' => (string)$row['GuaranteeMode'],
                'public_url' => (string)$row['PublicUrl'],
                'federation_url' => (string)$row['FederationUrl'],
                'last_seen' => (string)$row['LastSeen'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    public function storeAuthorization(array $row): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO FederationDropIngressObjects
                (IngressId, DropId, CommerceNodeId, IngressNodeId, IngressFederationUrl,
                 S3Key, ExpectedSizeBytes, MimeType, GrantJson, Status, NextAttemptAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'authorized', UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
                 IngressFederationUrl=VALUES(IngressFederationUrl),
                 S3Key=COALESCE(VALUES(S3Key), S3Key),
                 ExpectedSizeBytes=VALUES(ExpectedSizeBytes),
                 MimeType=VALUES(MimeType),
                 GrantJson=VALUES(GrantJson),
                 Status=IF(Status IN ('centralized','deleted'), Status, 'authorized'),
                 NextAttemptAt=IF(Status IN ('centralized','deleted'), NextAttemptAt, UTC_TIMESTAMP(6)),
                 LastError=IF(Status IN ('centralized','deleted'), LastError, NULL),
                 UpdatedAt=UTC_TIMESTAMP(6)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar ingress FederationDrop.', 500);
        $stmt->bind_param(
            'ssssssiss',
            $row['ingress_id'],
            $row['drop_id'],
            $row['commerce_node_id'],
            $row['ingress_node_id'],
            $row['ingress_federation_url'],
            $row['s3_key'],
            $row['expected_size_bytes'],
            $row['mime_type'],
            $row['grant_json']
        );
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo guardar ingress FederationDrop: ' . $message, 500);
        }
        $stmt->close();
    }

    public function find(string $ingressId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT IngressId, DropId, CommerceNodeId, IngressNodeId, IngressFederationUrl,
                    S3Key, ExpectedSizeBytes, MimeType, ObjectEtag, GrantJson, Status,
                    Attempts, LastAttemptAt, NextAttemptAt, LastError, UploadedAt, CentralizedAt,
                    CreatedAt, UpdatedAt
             FROM FederationDropIngressObjects WHERE IngressId=? LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar ingress FederationDrop.', 500);
        $stmt->bind_param('s', $ingressId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function activeForDrop(string $dropId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT IngressId FROM FederationDropIngressObjects
             WHERE DropId=? AND Status IN ('authorized','uploaded','pulling')
             ORDER BY FIELD(Status,'pulling','uploaded','authorized'), CreatedAt DESC LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('s', $dropId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $this->find((string)$row['IngressId']) : null;
    }

    public function markUploaded(string $ingressId, string $etag): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDropIngressObjects
             SET Status='uploaded', ObjectEtag=?, UploadedAt=COALESCE(UploadedAt, UTC_TIMESTAMP(6)),
                 NextAttemptAt=UTC_TIMESTAMP(6), LastError=NULL, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE IngressId=? AND Status IN ('authorized','uploaded','pulling') LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo marcar ingress subido.', 500);
        $stmt->bind_param('ss', $etag, $ingressId);
        $stmt->execute();
        $stmt->close();
    }

    public function markRemoteUploaded(string $ingressId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDropIngressObjects
             SET Status='uploaded', UploadedAt=COALESCE(UploadedAt, UTC_TIMESTAMP(6)),
                 NextAttemptAt=UTC_TIMESTAMP(6), LastError=NULL, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE IngressId=? AND Status IN ('authorized','uploaded','pulling') LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo registrar ingress remoto.', 500);
        $stmt->bind_param('s', $ingressId);
        $stmt->execute();
        $stmt->close();
    }

    public function dueCentral(int $limit = 2): array
    {
        $limit = max(1, min(10, $limit));
        $this->db->query(
            "UPDATE FederationDropIngressObjects
             SET Status='uploaded',
                 NextAttemptAt=UTC_TIMESTAMP(6),
                 LastError='La migración central anterior se interrumpió y fue recuperada por el worker.',
                 UpdatedAt=UTC_TIMESTAMP(6)
             WHERE Status='pulling'
               AND LastAttemptAt IS NOT NULL
               AND LastAttemptAt < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 HOUR)"
        );

        $sql = "SELECT i.*
                FROM FederationDropIngressObjects i
                INNER JOIN FederationDrops d ON d.DropId=i.DropId
                WHERE i.Status='uploaded'
                  AND d.PaymentStatus='paid'
                  AND d.Status='pending_upload'
                  AND d.SourceMode='upload'
                  AND (i.NextAttemptAt IS NULL OR i.NextAttemptAt <= UTC_TIMESTAMP(6))
                ORDER BY COALESCE(i.NextAttemptAt, i.CreatedAt) ASC, i.CreatedAt ASC
                LIMIT {$limit}";
        $result = $this->db->query($sql);
        if (!$result) throw new FederationException('No se pudo consultar ingress pendiente de centralizar.', 500);
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    public function markPulling(string $ingressId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDropIngressObjects
             SET Status='pulling', Attempts=Attempts+1, LastAttemptAt=UTC_TIMESTAMP(6),
                 NextAttemptAt=NULL, LastError=NULL, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE IngressId=? AND Status='uploaded' LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('s', $ingressId);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        return $claimed;
    }

    public function markCentralized(string $ingressId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDropIngressObjects
             SET Status='centralized', CentralizedAt=UTC_TIMESTAMP(6), NextAttemptAt=NULL,
                 LastError=NULL, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE IngressId=? LIMIT 1"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $ingressId);
        $stmt->execute();
        $stmt->close();
    }

    public function staleLocalObjects(int $days = 7, int $limit = 20): array
    {
        $days = max(1, min(30, $days));
        $limit = max(1, min(100, $limit));
        $sql = "SELECT IngressId, S3Key
                FROM FederationDropIngressObjects
                WHERE S3Key IS NOT NULL
                  AND S3Key <> ''
                  AND Status IN ('authorized','uploaded','failed')
                  AND CreatedAt <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL {$days} DAY)
                ORDER BY CreatedAt ASC
                LIMIT {$limit}";
        $result = $this->db->query($sql);
        if (!$result) throw new FederationException('No se pudieron consultar ingress temporales vencidos.', 500);
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    public function markDeleted(string $ingressId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDropIngressObjects
             SET Status='deleted', NextAttemptAt=NULL, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE IngressId=? LIMIT 1"
        );
        if (!$stmt) return;
        $stmt->bind_param('s', $ingressId);
        $stmt->execute();
        $stmt->close();
    }

    public function markRetry(string $ingressId, string $error): string
    {
        $row = $this->find($ingressId);
        if ($row === null) return 'failed';
        $attempts = max(1, (int)$row['Attempts']);
        $error = $this->cleanError($error);
        if ($attempts >= 5) {
            $stmt = $this->db->prepare(
                "UPDATE FederationDropIngressObjects
                 SET Status='failed', NextAttemptAt=NULL, LastError=?, UpdatedAt=UTC_TIMESTAMP(6)
                 WHERE IngressId=? LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('ss', $error, $ingressId);
                $stmt->execute();
                $stmt->close();
            }
            return 'failed';
        }

        $delay = min(3600, 30 * (2 ** min(7, $attempts - 1)));
        $next = gmdate('Y-m-d H:i:s', time() + $delay);
        $stmt = $this->db->prepare(
            "UPDATE FederationDropIngressObjects
             SET Status='uploaded', NextAttemptAt=?, LastError=?, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE IngressId=? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('sss', $next, $error, $ingressId);
            $stmt->execute();
            $stmt->close();
        }
        return 'retry';
    }

    private function cleanError(string $error): string
    {
        $error = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error);
        return function_exists('mb_substr') ? mb_substr($error, 0, 512) : substr($error, 0, 512);
    }
}
