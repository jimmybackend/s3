-- FederationCloud decentralized global catalog
-- Safe to run more than once on MySQL 8 / MariaDB versions that support JSON.

CREATE TABLE IF NOT EXISTS FederationOriginCounters (
  OriginNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  LastSequence bigint unsigned NOT NULL DEFAULT 0,
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (OriginNodeId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS FederationEvents (
  EventId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  OriginNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  OriginSequence bigint unsigned NOT NULL,
  EventType varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  EntityId varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PayloadJson json NOT NULL,
  IssuedAt varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Texto original firmado; no reformatear',
  PublicKey varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  Signature varchar(256) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ReceivedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (EventId),
  UNIQUE KEY uq_federation_event_origin_sequence (OriginNodeId, OriginSequence),
  KEY idx_federation_event_origin_sequence (OriginNodeId, OriginSequence),
  KEY idx_federation_event_received (ReceivedAt),
  KEY idx_federation_event_entity (EntityId, EventType)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS FederationClocks (
  OriginNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ContiguousSequence bigint unsigned NOT NULL DEFAULT 0,
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (OriginNodeId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS FederatedResources (
  ResourceId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  OriginNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  OwnerUserId int DEFAULT NULL COMMENT 'Sólo se conserva en el nodo propietario; nunca se replica',
  ResourceType varchar(32) NOT NULL DEFAULT 'file',
  Title varchar(255) NOT NULL,
  MediaType varchar(128) NOT NULL DEFAULT 'application/octet-stream',
  SizeBytes bigint unsigned NOT NULL DEFAULT 0,
  ContentId varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  Visibility enum('PUBLIC','UNLISTED','PRIVATE') NOT NULL DEFAULT 'UNLISTED',
  DiscoveryPolicy enum('local_only','public_metadata','requestable_metadata') NOT NULL DEFAULT 'local_only',
  Rights varchar(64) NOT NULL DEFAULT 'link_only',
  OriginUrl varchar(512) NOT NULL,
  FederationUrl varchar(512) NOT NULL,
  ArcadeLinkJson mediumtext DEFAULT NULL,
  LastOriginSequence bigint unsigned NOT NULL DEFAULT 0,
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  Tombstoned tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (ResourceId),
  KEY idx_fed_resource_origin (OriginNodeId, Tombstoned),
  KEY idx_fed_resource_discovery (DiscoveryPolicy, Tombstoned, UpdatedAt),
  KEY idx_fed_resource_content (ContentId),
  KEY idx_fed_resource_media (MediaType),
  FULLTEXT KEY ft_fed_resource_title (Title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS FederationResourceLocations (
  ResourceId varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  NodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  LocationRole enum('origin','provider','mirror') NOT NULL DEFAULT 'origin',
  Status enum('active','stale','revoked') NOT NULL DEFAULT 'active',
  FederationUrl varchar(512) NOT NULL,
  LastOriginSequence bigint unsigned NOT NULL DEFAULT 0,
  LastSeenAt datetime(6) DEFAULT NULL,
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (ResourceId, NodeId),
  KEY idx_fed_location_node_status (NodeId, Status),
  KEY idx_fed_location_resource_status (ResourceId, Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS FederationPeerSyncState (
  PeerNodeId varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  LastAttemptAt datetime(6) DEFAULT NULL,
  LastSuccessAt datetime(6) DEFAULT NULL,
  ConsecutiveFailures int unsigned NOT NULL DEFAULT 0,
  NextAttemptAt datetime(6) DEFAULT NULL,
  LastError varchar(512) DEFAULT NULL,
  LastPulledEvents int unsigned NOT NULL DEFAULT 0,
  LastPushedEvents int unsigned NOT NULL DEFAULT 0,
  UpdatedAt datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (PeerNodeId),
  KEY idx_fed_peer_next_attempt (NextAttemptAt, ConsecutiveFailures)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
