-- FederationCloud replica transfer/control plane.
-- Local operational state only; it is NOT copied through FederationEvents.

CREATE TABLE IF NOT EXISTS FederationReplicaJobs (
  OfferId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  Direction enum('outgoing','incoming') NOT NULL,
  ResourceId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  LocalUserId int DEFAULT NULL COMMENT 'Sólo significativo en la DB local; nunca viaja en el protocolo',
  RemoteNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  RemoteFederationUrl varchar(512) NOT NULL,
  Role enum('provider','mirror') NOT NULL,
  Status enum('queued','offered','transferring','stored','active','retry','failed','expired') NOT NULL DEFAULT 'queued',
  SourceStorageRef varchar(1024) DEFAULT NULL COMMENT 'Sólo origen local; nunca se serializa al catálogo global',
  OfferJson mediumtext DEFAULT NULL COMMENT 'Oferta privada firmada; nunca se gossip-ea',
  LocalS3Key varchar(1024) DEFAULT NULL,
  ContentId varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  SizeBytes bigint unsigned NOT NULL DEFAULT 0,
  Title varchar(255) NOT NULL,
  MediaType varchar(128) NOT NULL DEFAULT 'application/octet-stream',
  Attempts int unsigned NOT NULL DEFAULT 0,
  NextAttemptAt datetime(6) DEFAULT NULL,
  LastError varchar(512) DEFAULT NULL,
  ExpiresAt datetime(6) DEFAULT NULL,
  CreatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (OfferId),
  KEY idx_fed_replica_job_due (Direction, Status, NextAttemptAt),
  KEY idx_fed_replica_job_resource (ResourceId, Direction, Status),
  KEY idx_fed_replica_job_remote (RemoteNodeId, Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS FederationReplicaObjects (
  ResourceId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  OriginNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  Role enum('provider','mirror') NOT NULL,
  S3Key varchar(1024) NOT NULL,
  ContentId varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  SizeBytes bigint unsigned NOT NULL DEFAULT 0,
  Status enum('stored','active','stale','revoked') NOT NULL DEFAULT 'stored',
  VerifiedAt datetime(6) DEFAULT NULL,
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (ResourceId),
  KEY idx_fed_replica_object_origin (OriginNodeId, Status),
  KEY idx_fed_replica_object_status (Status, UpdatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
