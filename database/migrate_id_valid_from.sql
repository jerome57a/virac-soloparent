-- ID start date entered by MSWDO staff.
-- When MSWDO staff confirm that the Solo Parent received the ID, they type the start date and the expiration date printed on the
-- card. The expiration date already has a column (applications.id_expires_at); this adds the start date beside it.
--   applications.id_valid_from  DATE NULL  -- the start date printed on the ID (NULL for IDs released before this change)
-- Nothing else is changed: existing released IDs keep their expiry date, and their start date stays empty (the screens then show
-- the handover date instead). Safe to run more than once. Back up the database first.
SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'id_valid_from'),
  'DO 0',
  'ALTER TABLE applications ADD COLUMN id_valid_from DATE NULL AFTER id_expires_at'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;
SET @schema_upgrade_sql = NULL;
