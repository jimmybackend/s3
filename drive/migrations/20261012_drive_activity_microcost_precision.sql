-- One-time incremental upgrade for existing ArcadeCloud Drive installations.
-- Run against the configured Drive database only after confirming backups.
-- This is NOT the canonical full schema (which drops tables).
-- Preserves all existing DriveActivityEvents rows; previous rounding cannot be recovered.
-- Decimal(28,16) stores up to 16 digits after the decimal point.
ALTER TABLE `DriveActivityEvents`
    MODIFY COLUMN `EstimatedCost` DECIMAL(28,16) NULL DEFAULT NULL;
