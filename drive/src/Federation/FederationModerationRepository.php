<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;
use Throwable;

final class FederationModerationRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function contentIdForDrop(string $dropId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT ContentId FROM FederationContentFingerprints
             WHERE SubjectType='drop' AND SubjectId=? ORDER BY CreatedAt DESC LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar la huella del FederationDrop.', 500);
        $stmt->bind_param('s', $dropId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) && is_string($row['ContentId'] ?? null) ? (string)$row['ContentId'] : null;
    }

    public function rememberFingerprint(
        string $contentId,
        string $subjectType,
        string $subjectId,
        string $authorityNodeId,
        ?string $s3Key = null
    ): void {
        $contentId = $this->normalizeContentId($contentId);
        if (!in_array($subjectType, ['drop','resource','file','replica'], true)) {
            throw new FederationException('Tipo de sujeto de huella inválido.', 400);
        }
        $stmt = $this->db->prepare(
            "INSERT INTO FederationContentFingerprints
             (ContentId, SubjectType, SubjectId, AuthorityNodeId, S3Key, CreatedAt, UpdatedAt)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE AuthorityNodeId=VALUES(AuthorityNodeId), S3Key=COALESCE(VALUES(S3Key),S3Key), UpdatedAt=UTC_TIMESTAMP(6)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la huella federada.', 500);
        $stmt->bind_param('sssss', $contentId, $subjectType, $subjectId, $authorityNodeId, $s3Key);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo guardar la huella federada: ' . $message, 500);
        }
        $stmt->close();
    }

    public function isBlocked(string $contentId): bool
    {
        $contentId = $this->normalizeContentId($contentId);
        $stmt = $this->db->prepare(
            "SELECT 1 FROM FederationModerationBlocks
             WHERE ContentId=? AND Status='active' LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar la lista de bloqueo.', 500);
        $stmt->bind_param('s', $contentId);
        $stmt->execute();
        $stmt->store_result();
        $blocked = $stmt->num_rows > 0;
        $stmt->close();
        return $blocked;
    }

    public function createReport(
        string $reportId,
        string $targetType,
        string $targetId,
        ?string $contentId,
        string $targetNodeId,
        string $category,
        string $details,
        ?string $reporterEmail
    ): array {
        if (!in_array($targetType, ['drop','resource'], true)) throw new FederationException('Objetivo de reporte inválido.', 400);
        if (!in_array($category, ['spam','malware','illegal','abuse','copyright','other'], true)) {
            throw new FederationException('Categoría de reporte inválida.', 400);
        }
        $stmt = $this->db->prepare(
            "INSERT INTO FederationAbuseReports
             (ReportId, TargetType, TargetId, ContentId, TargetNodeId, Category, Details, ReporterEmail, Status, CreatedAt, UpdatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar el reporte de abuso.', 500);
        $stmt->bind_param('ssssssss', $reportId, $targetType, $targetId, $contentId, $targetNodeId, $category, $details, $reporterEmail);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo registrar el reporte: ' . $message, 500);
        }
        $stmt->close();
        return $this->report($reportId) ?? [];
    }

    public function report(string $reportId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ReportId, TargetType, TargetId, ContentId, TargetNodeId, Category, Details,
                    ReporterEmail, Status, DecisionReason, DecidedByUserId, CreatedAt, UpdatedAt, DecidedAt
             FROM FederationAbuseReports WHERE ReportId=? LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar el reporte.', 500);
        $stmt->bind_param('s', $reportId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function pendingReports(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $result = $this->db->query(
            "SELECT ReportId, TargetType, TargetId, ContentId, TargetNodeId, Category, Details,
                    ReporterEmail, Status, DecisionReason, DecidedByUserId, CreatedAt, UpdatedAt, DecidedAt
             FROM FederationAbuseReports
             WHERE Status IN ('pending','reviewing')
             ORDER BY CreatedAt ASC LIMIT {$limit}"
        );
        if (!$result) throw new FederationException('No se pudieron consultar reportes pendientes.', 500);
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
        return $rows;
    }

    public function activeBlocks(string $originNodeId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            "SELECT b.ContentId, b.OriginNodeId, b.ReasonCode, b.ReportId, b.EventId, b.BlockedAt, b.UpdatedAt,
                    r.TargetType, r.TargetId, r.Category, r.DecisionReason
             FROM FederationModerationBlocks b
             LEFT JOIN FederationAbuseReports r ON r.ReportId=b.ReportId
             WHERE b.Status='active' AND b.OriginNodeId=?
             ORDER BY b.BlockedAt DESC LIMIT {$limit}"
        );
        if (!$stmt) throw new FederationException('No se pudieron consultar bloqueos activos.', 500);
        $stmt->bind_param('s', $originNodeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    public function activeBlock(string $contentId, string $originNodeId): ?array
    {
        $contentId = $this->normalizeContentId($contentId);
        $stmt = $this->db->prepare(
            "SELECT ContentId, OriginNodeId, ReasonCode, ReportId, EventId, BlockedAt, UpdatedAt
             FROM FederationModerationBlocks
             WHERE ContentId=? AND OriginNodeId=? AND Status='active' LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo consultar el bloqueo activo.', 500);
        $stmt->bind_param('ss', $contentId, $originNodeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function decideReport(string $reportId, string $status, int $userId, string $reason): array
    {
        if (!in_array($status, ['confirmed','rejected'], true)) throw new FederationException('Decisión de moderación inválida.', 400);
        $stmt = $this->db->prepare(
            "UPDATE FederationAbuseReports
             SET Status=?, DecisionReason=?, DecidedByUserId=?, DecidedAt=UTC_TIMESTAMP(6), UpdatedAt=UTC_TIMESTAMP(6)
             WHERE ReportId=? AND Status IN ('pending','reviewing') LIMIT 1"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la decisión de moderación.', 500);
        $stmt->bind_param('ssis', $status, $reason, $userId, $reportId);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$changed) throw new FederationException('El reporte ya fue resuelto o no existe.', 409);
        return $this->report($reportId) ?? [];
    }

    public function activateBlock(
        string $contentId,
        string $originNodeId,
        string $reasonCode,
        ?string $reportId,
        string $eventId
    ): void {
        $contentId = $this->normalizeContentId($contentId);
        $stmt = $this->db->prepare(
            "INSERT INTO FederationModerationBlocks
             (ContentId, OriginNodeId, ReasonCode, ReportId, EventId, Status, BlockedAt, UpdatedAt)
             VALUES (?, ?, ?, ?, ?, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE OriginNodeId=VALUES(OriginNodeId), ReasonCode=VALUES(ReasonCode),
               ReportId=COALESCE(VALUES(ReportId),ReportId), EventId=VALUES(EventId), Status='active',
               BlockedAt=UTC_TIMESTAMP(6), UpdatedAt=UTC_TIMESTAMP(6)"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar el bloqueo de contenido.', 500);
        $stmt->bind_param('sssss', $contentId, $originNodeId, $reasonCode, $reportId, $eventId);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new FederationException('No se pudo guardar el bloqueo de contenido: ' . $message, 500);
        }
        $stmt->close();
    }

    public function revokeBlock(string $contentId, string $originNodeId, string $eventId): void
    {
        $contentId = $this->normalizeContentId($contentId);
        $stmt = $this->db->prepare(
            "UPDATE FederationModerationBlocks
             SET Status='revoked', EventId=?, UpdatedAt=UTC_TIMESTAMP(6)
             WHERE ContentId=? AND OriginNodeId=?"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la revocación del bloqueo.', 500);
        $stmt->bind_param('sss', $eventId, $contentId, $originNodeId);
        $stmt->execute();
        $stmt->close();
    }

    public function blockedContentIds(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $result = $this->db->query(
            "SELECT ContentId FROM FederationModerationBlocks
             WHERE Status='active' ORDER BY BlockedAt ASC LIMIT {$limit}"
        );
        if (!$result) throw new FederationException('No se pudo consultar contenido bloqueado.', 500);
        $ids = [];
        while ($row = $result->fetch_assoc()) $ids[] = (string)$row['ContentId'];
        $result->free();
        return $ids;
    }

    public function fingerprintSubjects(string $contentId): array
    {
        $contentId = $this->normalizeContentId($contentId);
        $stmt = $this->db->prepare(
            "SELECT SubjectType, SubjectId, AuthorityNodeId, S3Key
             FROM FederationContentFingerprints WHERE ContentId=?"
        );
        if (!$stmt) throw new FederationException('No se pudieron consultar sujetos de la huella.', 500);
        $stmt->bind_param('s', $contentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    public function audit(
        string $actionId,
        string $action,
        string $contentId,
        ?string $reportId,
        ?int $actorUserId,
        string $nodeId,
        string $detailsJson
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO FederationModerationActions
             (ActionId, Action, ContentId, ReportId, ActorUserId, NodeId, DetailsJson, CreatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))"
        );
        if (!$stmt) throw new FederationException('No se pudo preparar la auditoría de moderación.', 500);
        $stmt->bind_param('ssssiss', $actionId, $action, $contentId, $reportId, $actorUserId, $nodeId, $detailsJson);
        try {
            if (!$stmt->execute()) {
                $message = $stmt->error;
                $stmt->close();
                throw new FederationException('No se pudo registrar la auditoría de moderación: ' . $message, 500);
            }
        } catch (Throwable $e) {
            $stmt->close();
            if ($e instanceof FederationException) throw $e;
            throw new FederationException('No se pudo registrar la auditoría de moderación.', 500);
        }
        $stmt->close();
    }

    public function superAdminEmails(): array
    {
        $result = $this->db->query(
            "SELECT email FROM Users
             WHERE system_role='superadmin' AND userstatus='Activo' AND email <> ''
             ORDER BY id ASC"
        );
        if (!$result) return [];
        $emails = [];
        while ($row = $result->fetch_assoc()) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[] = $email;
        }
        $result->free();
        return array_values(array_unique($emails));
    }

    public function resolveTarget(string $targetType, string $targetId): ?array
    {
        if ($targetType === 'drop') {
            $stmt = $this->db->prepare(
                "SELECT DropId AS TargetId, CustodyNodeId AS TargetNodeId, OriginalName AS Title, S3Key
                 FROM FederationDrops WHERE DropId=? LIMIT 1"
            );
            if (!$stmt) return null;
            $stmt->bind_param('s', $targetId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) return null;
            $row['ContentId'] = $this->contentIdForDrop($targetId);
            return $row;
        }
        if ($targetType === 'resource') {
            $stmt = $this->db->prepare(
                "SELECT ResourceId AS TargetId, OriginNodeId AS TargetNodeId, Title, ContentId, NULL AS S3Key
                 FROM FederatedResources WHERE ResourceId=? AND Tombstoned=0 LIMIT 1"
            );
            if (!$stmt) return null;
            $stmt->bind_param('s', $targetId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return is_array($row) ? $row : null;
        }
        return null;
    }

    private function normalizeContentId(string $contentId): string
    {
        $contentId = strtolower(trim($contentId));
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentId)) {
            throw new FederationException('Content ID SHA-256 inválido.', 400);
        }
        return $contentId;
    }
}
