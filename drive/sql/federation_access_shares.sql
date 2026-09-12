-- FederationCloud private access requests and logical Shares zone

CREATE TABLE IF NOT EXISTS FederationAccessRequests (
  RequestId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  Direction enum('outgoing','incoming') NOT NULL,
  ResourceId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  LocalUserId int NOT NULL,
  RemoteNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  RemoteFederationUrl varchar(512) NOT NULL,
  Status enum('queued','pending','approved','rejected','expired','failed') NOT NULL DEFAULT 'queued',
  RequestJson mediumtext NOT NULL,
  DecisionJson mediumtext DEFAULT NULL,
  RequestedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ExpiresAt datetime(6) NOT NULL,
  LastAttemptAt datetime(6) DEFAULT NULL,
  NextAttemptAt datetime(6) DEFAULT NULL,
  Attempts int unsigned NOT NULL DEFAULT 0,
  LastError varchar(512) DEFAULT NULL,
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (RequestId),
  KEY idx_far_local_direction_status (LocalUserId, Direction, Status, UpdatedAt),
  KEY idx_far_retry (Direction, Status, NextAttemptAt),
  KEY idx_far_resource (ResourceId, Direction, Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS FederationShares (
  ShareId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  UserId int NOT NULL,
  Direction enum('received','sent') NOT NULL,
  ResourceId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  RemoteNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  Title varchar(255) NOT NULL,
  MediaType varchar(128) NOT NULL DEFAULT 'application/octet-stream',
  Status enum('active','expired','revoked') NOT NULL DEFAULT 'active',
  AccessUrl varchar(2048) DEFAULT NULL,
  DecisionJson mediumtext NOT NULL,
  ExpiresAt datetime(6) DEFAULT NULL,
  LocalFileId int DEFAULT NULL,
  LocalS3Key varchar(1024) DEFAULT NULL,
  ImportedAt datetime(6) DEFAULT NULL,
  ImportedResourceUpdatedAt datetime(6) DEFAULT NULL,
  CreatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (ShareId),
  KEY idx_fshares_user_direction_status (UserId, Direction, Status, UpdatedAt),
  KEY idx_fshares_resource (ResourceId, Status),
  KEY idx_fshares_remote (RemoteNodeId, Status),
  KEY idx_fshares_local_file (UserId, LocalFileId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing installations need these columns too. MariaDB/MySQL safely ignore
-- the ALTER when the column/index already exists.
ALTER TABLE FederationShares ADD COLUMN IF NOT EXISTS LocalFileId int DEFAULT NULL AFTER ExpiresAt;
ALTER TABLE FederationShares ADD COLUMN IF NOT EXISTS LocalS3Key varchar(1024) DEFAULT NULL AFTER LocalFileId;
ALTER TABLE FederationShares ADD COLUMN IF NOT EXISTS ImportedAt datetime(6) DEFAULT NULL AFTER LocalS3Key;
ALTER TABLE FederationShares ADD COLUMN IF NOT EXISTS ImportedResourceUpdatedAt datetime(6) DEFAULT NULL AFTER ImportedAt;
ALTER TABLE FederationShares ADD INDEX IF NOT EXISTS idx_fshares_local_file (UserId, LocalFileId);
