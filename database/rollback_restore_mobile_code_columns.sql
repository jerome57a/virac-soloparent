-- PROPOSED, NOT EXECUTED. Structure-only rollback for migrate_drop_mobile_code_columns.php.
--
-- This re-adds the three columns with their original definitions. It CANNOT restore
-- the values that were in them: those come back only by restoring the database copy
-- from the dump taken before the migration. Use this file only on a disposable copy
-- (virac_solo_parent_test) and only after an administrator reviews it; it has no USE
-- statement on purpose, so the target is whatever database the operator selected.
ALTER TABLE users
  ADD COLUMN mobile_code VARCHAR(255) DEFAULT NULL AFTER mobile_verified_at,
  ADD COLUMN mobile_code_expires_at DATETIME DEFAULT NULL AFTER mobile_code,
  ADD COLUMN mobile_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER mobile_code_expires_at;
