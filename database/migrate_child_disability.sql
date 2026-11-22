-- "Child with a disability" (RA 11861): a child over 22 still qualifies when they cannot fully take care of themselves because of
-- a physical or mental disability. The Solo Parent ticks it on the renewal form and uploads a PWD ID or medical certificate.
--   application_children.has_disability  TINYINT(1) NOT NULL DEFAULT 0
-- Existing children get 0 (not marked). Nothing else is changed. Safe to run more than once. Back up the database first.
SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'application_children' AND COLUMN_NAME = 'has_disability'),
  'DO 0',
  'ALTER TABLE application_children ADD COLUMN has_disability TINYINT(1) NOT NULL DEFAULT 0 AFTER birthdate'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;
SET @schema_upgrade_sql = NULL;
