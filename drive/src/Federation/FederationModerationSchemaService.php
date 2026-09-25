<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use mysqli;

final class FederationModerationSchemaService
{
    public function __construct(private mysqli $db)
    {
    }

    public function ensure(): array
    {
        $definitions = [
            'FederationContentFingerprints' => <<<'SQL'
CREATE TABLE IF NOT EXISTS FederationContentFingerprints (
  ContentId varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  SubjectType enum('drop','resource','file','replica') NOT NULL,
  SubjectId varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  AuthorityNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  S3Key varchar(1024) DEFAULT NULL,
  CreatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (ContentId, SubjectType, SubjectId),
  KEY idx_fcf_subject (SubjectType, SubjectId),
  KEY idx_fcf_authority (AuthorityNodeId, ContentId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'FederationAbuseReports' => <<<'SQL'
CREATE TABLE IF NOT EXISTS FederationAbuseReports (
  ReportId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  TargetType enum('drop','resource') NOT NULL,
  TargetId varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ContentId varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  TargetNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  Category enum('spam','malware','illegal','abuse','copyright','other') NOT NULL,
  Details varchar(2000) NOT NULL,
  ReporterEmail varchar(320) DEFAULT NULL,
  Status enum('pending','reviewing','confirmed','rejected') NOT NULL DEFAULT 'pending',
  DecisionReason varchar(1000) DEFAULT NULL,
  DecidedByUserId int DEFAULT NULL,
  CreatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  DecidedAt datetime(6) DEFAULT NULL,
  PRIMARY KEY (ReportId),
  KEY idx_far_status (Status, CreatedAt),
  KEY idx_far_target (TargetType, TargetId, CreatedAt),
  KEY idx_far_content (ContentId, Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'FederationModerationBlocks' => <<<'SQL'
CREATE TABLE IF NOT EXISTS FederationModerationBlocks (
  ContentId varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  OriginNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ReasonCode varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ReportId varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  EventId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  Status enum('active','revoked') NOT NULL DEFAULT 'active',
  BlockedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (ContentId, OriginNodeId),
  KEY idx_fmb_status (Status, BlockedAt),
  KEY idx_fmb_report (ReportId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'FederationModerationActions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS FederationModerationActions (
  ActionId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  Action varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ContentId varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ReportId varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  ActorUserId int DEFAULT NULL,
  NodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  DetailsJson mediumtext,
  CreatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (ActionId),
  KEY idx_fma_content (ContentId, CreatedAt),
  KEY idx_fma_report (ReportId, CreatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];

        $created = [];
        foreach ($definitions as $table => $sql) {
            if (!$this->db->query($sql)) {
                throw new FederationException(
                    'No se pudo preparar la tabla de moderación ' . $table . ': ' . $this->db->error,
                    503
                );
            }
            $created[] = $table;
        }

        return [
            'ok' => true,
            'tables' => $created,
            'message' => 'Esquema de moderación FederationCloud preparado.',
        ];
    }
}
