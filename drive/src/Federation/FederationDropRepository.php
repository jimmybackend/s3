<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationDropRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function create(array $row): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO FederationDrops
            (DropId, OwnerEmail, OwnerTokenHash, OwnerTokenCiphertext, PublicTokenHash, PublicTokenCiphertext, SourceDomain, OriginalName, S3Key,
             MimeType, ExpectedSizeBytes, RetentionDays, MaxDownloads, AmountCents, Currency,
             PaymentStatus, Status, CustodyNodeId, CreatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending_payment', ?, UTC_TIMESTAMP(6))"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar FederationDrop.', 500);
        $stmt->bind_param(
            'ssssssssssiiiiss',
            $row['drop_id'],
            $row['owner_email'],
            $row['owner_token_hash'],
            $row['owner_token_ciphertext'],
            $row['public_token_hash'],
            $row['public_token_ciphertext'],
            $row['source_domain'],
            $row['original_name'],
            $row['s3_key'],
            $row['mime_type'],
            $row['expected_size_bytes'],
            $row['retention_days'],
            $row['max_downloads'],
            $row['amount_cents'],
            $row['currency'],
            $row['custody_node_id']
        );
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo crear FederationDrop: ' . $message, 500);
        }
        $stmt->close();
    }

    public function find(string $dropId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT DropId, OwnerEmail, OwnerTokenHash, OwnerTokenCiphertext, PublicTokenHash, PublicTokenCiphertext, SourceDomain, OriginalName, S3Key,
                    MimeType, ExpectedSizeBytes, ActualSizeBytes, ObjectEtag, RetentionDays, MaxDownloads,
                    DownloadCount, AmountCents, Currency, PaymentStatus, PaymentProvider, PaymentReference,
                    CheckoutUrl, Status, CustodyNodeId, CreatedAt, UploadedAt, PaidAt, ExpiresAt,
                    LastDownloadAt, DeletedAt
             FROM FederationDrops WHERE DropId = ? LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo consultar FederationDrop.', 500);
        $stmt->bind_param('s', $dropId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo consultar FederationDrop: ' . $message, 500);
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function setCheckoutUrl(string $dropId, string $checkoutUrl): void
    {
        $stmt = $this->db->prepare(
            'UPDATE FederationDrops SET CheckoutUrl = ? WHERE DropId = ? AND Status IN (\'pending_upload\',\'pending_payment\') LIMIT 1'
        );
        if (!$stmt) throw new FederationException('No se pudo preparar el checkout FederationDrop.', 500);
        $stmt->bind_param('ss', $checkoutUrl, $dropId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo guardar el checkout FederationDrop: ' . $message, 500);
        }
        $stmt->close();
    }

    public function markUploaded(string $dropId, int $actualBytes, string $etag, string $mimeType): array
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDrops
             SET ActualSizeBytes = ?, ObjectEtag = ?, MimeType = ?, UploadedAt = UTC_TIMESTAMP(6),
                 Status = IF(PaymentStatus = 'paid', 'active', 'pending_payment'),
                 PaidAt = IF(PaymentStatus = 'paid' AND PaidAt IS NULL, UTC_TIMESTAMP(6), PaidAt),
                 ExpiresAt = IF(PaymentStatus = 'paid' AND ExpiresAt IS NULL,
                     DATE_ADD(UTC_TIMESTAMP(6), INTERVAL RetentionDays DAY), ExpiresAt)
             WHERE DropId = ? AND Status IN ('pending_upload','pending_payment') LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar el cierre de subida FederationDrop.', 500);
        $stmt->bind_param('isss', $actualBytes, $etag, $mimeType, $dropId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo cerrar la subida FederationDrop: ' . $message, 500);
        }
        $stmt->close();
        $row = $this->find($dropId);
        if ($row === null) throw new FederationException('FederationDrop no encontrado después de subir.', 404);
        return $row;
    }

    public function recordPaymentEvent(
        string $eventId,
        string $dropId,
        string $provider,
        string $reference,
        int $amountCents,
        string $currency,
        string $status,
        string $payloadHash
    ): bool {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO FederationDropPaymentEvents
             (EventId, DropId, Provider, ExternalReference, AmountCents, Currency, Status, PayloadHash, ReceivedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))'
        );
        if (!$stmt) throw new FederationException('No se pudo preparar el evento de pago FederationDrop.', 500);
        $stmt->bind_param(
            'ssssisss',
            $eventId,
            $dropId,
            $provider,
            $reference,
            $amountCents,
            $currency,
            $status,
            $payloadHash
        );
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo registrar el evento de pago: ' . $message, 500);
        }
        $inserted = $stmt->affected_rows === 1;
        $stmt->close();
        return $inserted;
    }

    public function markPaid(string $dropId, string $provider, string $reference): array
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDrops
             SET PaymentStatus = 'paid', PaymentProvider = ?, PaymentReference = ?,
                 PaidAt = COALESCE(PaidAt, UTC_TIMESTAMP(6)),
                 Status = IF(UploadedAt IS NULL, 'pending_upload', 'active'),
                 ExpiresAt = IF(UploadedAt IS NULL, ExpiresAt,
                     COALESCE(ExpiresAt, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL RetentionDays DAY)))
             WHERE DropId = ? AND PaymentStatus <> 'refunded'
               AND Status NOT IN ('deleted','blocked','expired') LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la activación del pago.', 500);
        $stmt->bind_param('sss', $provider, $reference, $dropId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo activar el pago FederationDrop: ' . $message, 500);
        }
        $stmt->close();
        $row = $this->find($dropId);
        if ($row === null) throw new FederationException('FederationDrop no encontrado.', 404);
        return $row;
    }

    public function createCentralPlacement(string $dropId, string $nodeId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO FederationDropPlacements
             (DropId, NodeId, PlacementRole, Status, CommissionBps, CreatedAt, UpdatedAt)
             VALUES (?, ?, 'primary', 'active', 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE Status = 'active', UpdatedAt = UTC_TIMESTAMP(6)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la custodia FederationDrop.', 500);
        $stmt->bind_param('ss', $dropId, $nodeId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo registrar la custodia FederationDrop: ' . $message, 500);
        }
        $stmt->close();
    }

    public function claimDownload(string $dropId, string $publicTokenHash): array
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT DropId, PublicTokenHash, OriginalName, S3Key, MimeType, ActualSizeBytes,
                        MaxDownloads, DownloadCount, Status, PaymentStatus, ExpiresAt
                 FROM FederationDrops WHERE DropId = ? LIMIT 1 FOR UPDATE"
            );
            if (!$stmt) throw new FederationException('No se pudo preparar la descarga FederationDrop.', 500);
            $stmt->bind_param('s', $dropId);
            if (!$stmt->execute()) {
                $message = $stmt->error;
                $stmt->close();
                throw new FederationException('No se pudo consultar la descarga FederationDrop: ' . $message, 500);
            }
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row) || !hash_equals((string)$row['PublicTokenHash'], $publicTokenHash)) {
                throw new FederationException('Enlace FederationDrop inválido.', 404);
            }
            if ((string)$row['PaymentStatus'] !== 'paid' || (string)$row['Status'] !== 'active') {
                throw new FederationException('Este FederationDrop no está disponible.', 410);
            }
            $expires = strtotime((string)$row['ExpiresAt'] . ' UTC');
            if ($expires === false || $expires <= time()) {
                $this->markExpiredInTransaction($dropId);
                throw new FederationException('Este FederationDrop ha vencido.', 410);
            }
            if ((int)$row['DownloadCount'] >= (int)$row['MaxDownloads']) {
                throw new FederationException('Este FederationDrop alcanzó su límite de descargas.', 410);
            }

            $update = $this->db->prepare(
                'UPDATE FederationDrops
                 SET DownloadCount = DownloadCount + 1, LastDownloadAt = UTC_TIMESTAMP(6)
                 WHERE DropId = ? LIMIT 1'
            );
            if (!$update) throw new FederationException('No se pudo contabilizar la descarga.', 500);
            $update->bind_param('s', $dropId);
            if (!$update->execute()) {
                $message = $update->error;
                $update->close();
                throw new FederationException('No se pudo contabilizar la descarga: ' . $message, 500);
            }
            $update->close();
            $this->db->commit();
            $row['DownloadCount'] = (int)$row['DownloadCount'] + 1;
            return $row;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function markDeleted(string $dropId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDrops
             SET Status = 'deleted', DeletedAt = UTC_TIMESTAMP(6)
             WHERE DropId = ? AND Status <> 'deleted' LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la eliminación FederationDrop.', 500);
        $stmt->bind_param('s', $dropId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo marcar FederationDrop como eliminado: ' . $message, 500);
        }
        $stmt->close();
    }

    public function cleanupCandidates(int $pendingHours, int $limit = 100): array
    {
        $pendingHours = max(1, min(72, $pendingHours));
        $limit = max(1, min(500, $limit));
        $sql =
            "SELECT DropId, S3Key, Status, PaymentStatus, UploadedAt, ExpiresAt, CreatedAt
             FROM FederationDrops
             WHERE (Status = 'active' AND ExpiresAt IS NOT NULL AND ExpiresAt <= UTC_TIMESTAMP(6))
                OR (Status IN ('pending_upload','pending_payment') AND CreatedAt <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL {$pendingHours} HOUR))
             ORDER BY CreatedAt ASC LIMIT {$limit}";
        $result = $this->db->query($sql);
        if (!$result) throw new FederationException('No se pudo listar FederationDrops vencidos.', 500);
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    public function markExpired(string $dropId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDrops SET Status = 'expired'
             WHERE DropId = ? AND Status NOT IN ('deleted','blocked') LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar expiración FederationDrop.', 500);
        $stmt->bind_param('s', $dropId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo expirar FederationDrop: ' . $message, 500);
        }
        $stmt->close();
    }

    private function markExpiredInTransaction(string $dropId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE FederationDrops SET Status = 'expired'
             WHERE DropId = ? AND Status = 'active' LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar expiración FederationDrop.', 500);
        $stmt->bind_param('s', $dropId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo expirar FederationDrop: ' . $message, 500);
        }
        $stmt->close();
    }
}
