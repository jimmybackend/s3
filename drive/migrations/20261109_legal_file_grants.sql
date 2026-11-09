-- Apply once to the existing Drive database on EC2. No runtime schema writes.
CREATE TABLE IF NOT EXISTS legal_file_grants (
  authorization_ref CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  owner_grant_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  service_grant_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  legal_case_id BIGINT UNSIGNED NOT NULL,
  authorized_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_legal_file_case (owner_user_id,file_id,service_grant_id,legal_case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- External Legal case IDs are audience-scoped references, never cross-database foreign keys.
