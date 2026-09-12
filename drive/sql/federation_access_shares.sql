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
  ImportedContentId varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  CreatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (ShareId),
  KEY idx_fshares_user_direction_status (UserId, Direction, Status, UpdatedAt),
  KEY idx_fshares_resource (ResourceId, Status),
  KEY idx_fshares_remote (RemoteNodeId, Status),
  KEY idx_fshares_local_file (UserId, LocalFileId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS FederationShareImportJobs (
  ImportId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  UserId int NOT NULL,
  ShareId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ResourceId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  VersionKey char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ResourceUpdatedAt datetime(6) DEFAULT NULL,
  Status enum('queued','processing','retry','completed','failed') NOT NULL DEFAULT 'queued',
  Attempts int unsigned NOT NULL DEFAULT 0,
  LastAttemptAt datetime(6) DEFAULT NULL,
  NextAttemptAt datetime(6) DEFAULT NULL,
  LastError varchar(512) DEFAULT NULL,
  LocalFileId int DEFAULT NULL,
  CreatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (ImportId),
  UNIQUE KEY uq_fshare_import_version (UserId, ShareId, VersionKey),
  KEY idx_fshare_import_due (Status, NextAttemptAt, CreatedAt),
  KEY idx_fshare_import_user (UserId, ShareId, UpdatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Portable upgrades for existing installations.
-- Do not use `ADD COLUMN IF NOT EXISTS`: that syntax differs across MySQL/MariaDB versions.
-- information_schema + prepared statements keeps this migration idempotent on both engines.

SET @arcade_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'FederationShares' AND COLUMN_NAME = 'LocalFileId') = 0,
  'ALTER TABLE FederationShares ADD COLUMN LocalFileId int DEFAULT NULL AFTER ExpiresAt',
  'SELECT 1'
);
PREPARE arcade_stmt FROM @arcade_sql;
EXECUTE arcade_stmt;
DEALLOCATE PREPARE arcade_stmt;

SET @arcade_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'FederationShares' AND COLUMN_NAME = 'LocalS3Key') = 0,
  'ALTER TABLE FederationShares ADD COLUMN LocalS3Key varchar(1024) DEFAULT NULL AFTER LocalFileId',
  'SELECT 1'
);
PREPARE arcade_stmt FROM @arcade_sql;
EXECUTE arcade_stmt;
DEALLOCATE PREPARE arcade_stmt;

SET @arcade_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'FederationShares' AND COLUMN_NAME = 'ImportedAt') = 0,
  'ALTER TABLE FederationShares ADD COLUMN ImportedAt datetime(6) DEFAULT NULL AFTER LocalS3Key',
  'SELECT 1'
);
PREPARE arcade_stmt FROM @arcade_sql;
EXECUTE arcade_stmt;
DEALLOCATE PREPARE arcade_stmt;

SET @arcade_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'FederationShares' AND COLUMN_NAME = 'ImportedResourceUpdatedAt') = 0,
  'ALTER TABLE FederationShares ADD COLUMN ImportedResourceUpdatedAt datetime(6) DEFAULT NULL AFTER ImportedAt',
  'SELECT 1'
);
PREPARE arcade_stmt FROM @arcade_sql;
EXECUTE arcade_stmt;
DEALLOCATE PREPARE arcade_stmt;

SET @arcade_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'FederationShares' AND COLUMN_NAME = 'ImportedContentId') = 0,
  'ALTER TABLE FederationShares ADD COLUMN ImportedContentId varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER ImportedResourceUpdatedAt',
  'SELECT 1'
);
PREPARE arcade_stmt FROM @arcade_sql;
EXECUTE arcade_stmt;
DEALLOCATE PREPARE arcade_stmt;

SET @arcade_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'FederationShares' AND INDEX_NAME = 'idx_fshares_local_file') = 0,
  'ALTER TABLE FederationShares ADD INDEX idx_fshares_local_file (UserId, LocalFileId)',
  'SELECT 1'
);
PREPARE arcade_stmt FROM @arcade_sql;
EXECUTE arcade_stmt;
DEALLOCATE PREPARE arcade_stmt;
