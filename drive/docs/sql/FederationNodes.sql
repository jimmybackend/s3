-- ArcadeCloud Drive / FederationCloud
-- Migración puntual. NO importar adbbmis1_Cloud.sql completo en producción.

CREATE TABLE IF NOT EXISTS `FederationNodes` (
  `NodeId` varchar(64) NOT NULL,
  `PublicKey` varchar(128) NOT NULL,
  `PublicUrl` varchar(512) NOT NULL,
  `FederationUrl` varchar(512) NOT NULL,
  `Status` enum('active','stale','blocked') NOT NULL DEFAULT 'active',
  `FirstSeen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `LastSeen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`NodeId`),
  KEY `idx_federation_nodes_status_last_seen` (`Status`,`LastSeen`),
  KEY `idx_federation_nodes_federation_url` (`FederationUrl`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
