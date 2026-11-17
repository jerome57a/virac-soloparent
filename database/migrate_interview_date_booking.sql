-- Interview date booking (database structure, plus two SMS template rows).
--
-- Applicants (and staff) reserve an interview DATE; there are no time slots. Staff open
-- dates and set a daily capacity. This file ONLY ADDS structure and two sms_templates rows
-- (interview_booking_open, interview_date_booked; INSERT IGNORE, existing rows untouched):
--   * new tables:   interview_dates, interview_invitations, appointment_events (+ 2 triggers)
--   * appointments: interview_date (generated), date_only, booking_source,
--                   confirmation_sms_log_id, idx_appt_interview_date, idx_appt_confirm_sms,
--                   fk_appt_confirm_sms, chk_appt_date_only
-- No existing row is changed, deleted or converted. Every existing appointment keeps its
-- scheduled_at (date AND time), slot_id, reminder_sent_at, result and SMS link, and receives
-- date_only = 0 and booking_source = 'LEGACY'. appointment_slots, id_release_schedules,
-- sms_logs and system_settings are not touched; sms_templates only gains the two new rows.
--
-- Safe to re-run: each step checks information_schema first and skips what already exists.
-- Select the target database before running it (this file has no USE statement), and take a
-- backup first. Needs MySQL 8.0.16 or later: CHECK constraints are enforced only from 8.0.16
-- (earlier versions parse them and ignore them, and the application's readiness check then
-- reports the schema as not ready), and a stored generated column needs MySQL 5.7 or later.
-- Run it with the mysql command-line client: the trigger section at the end uses the
-- client-side DELIMITER command, which other tools may not understand. Do NOT use --force:
-- the script must stop at its first error (the version guard below relies on that).
-- The account needs CREATE, ALTER, INDEX, REFERENCES, TRIGGER, SELECT and INSERT on the database.
-- If binary logging is on and the account lacks SUPER,
-- CREATE TRIGGER fails with error 1419; run it with a suitably privileged account instead.
-- Prerequisites (all part of the current schema): users, applications, appointments, sms_logs
-- and sms_templates. appointments.interview_sms_log_id (migrate_interview_sms_tracking.sql) is
-- used for column placement when present, but is not required.
--
-- ROLLBACK (not executed; see the commented section at the end of this file).

-- ---------------------------------------------------------------
-- 0. Version guard. Before MySQL 8.0.16 a CHECK constraint is parsed and then ignored, which would
--    leave the schema "not ready" for the application without any error. On such a server this step
--    fails on purpose (error 1146 naming the table below) so nothing is changed. Other servers pass.
-- ---------------------------------------------------------------
SET @sp_version = SUBSTRING_INDEX(VERSION(), '-', 1);
SET @sp_too_old = (VERSION() NOT LIKE '%MariaDB%') AND NOT (
  CAST(SUBSTRING_INDEX(@sp_version, '.', 1) AS UNSIGNED) > 8
  OR (CAST(SUBSTRING_INDEX(@sp_version, '.', 1) AS UNSIGNED) = 8
      AND (CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(@sp_version, '.', 2), '.', -1) AS UNSIGNED) > 0
           OR CAST(SUBSTRING_INDEX(@sp_version, '.', -1) AS UNSIGNED) >= 16))
);
SET @schema_upgrade_sql = IF(@sp_too_old,
  'ALTER TABLE this_migration_needs_mysql_8_0_16_or_later ADD COLUMN x INT',
  'DO 0'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

-- ---------------------------------------------------------------
-- 1. interview_dates: the staff-opened calendar and the date-level capacity lock.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS interview_dates (
  interview_date    DATE PRIMARY KEY,
  status            ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  capacity_override INT NULL,
  created_by        INT NULL,
  closed_by         INT NULL,
  closed_at         DATETIME DEFAULT NULL,
  closed_reason     VARCHAR(255) DEFAULT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_idate_status (status, interview_date),
  CONSTRAINT chk_idate_capacity CHECK (capacity_override IS NULL OR capacity_override >= 1),
  CONSTRAINT fk_idate_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_idate_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 2. interview_invitations: one per application; reopened, never duplicated.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS interview_invitations (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  application_id      INT NOT NULL,
  status              ENUM('OPEN','BOOKED','CLOSED') NOT NULL DEFAULT 'OPEN',
  reschedules_allowed TINYINT UNSIGNED NOT NULL DEFAULT 1,
  reschedules_used    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  opened_by           INT NULL,
  opened_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by           INT NULL,
  closed_at           DATETIME DEFAULT NULL,
  close_reason        VARCHAR(255) DEFAULT NULL,
  sms_log_id          INT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invite_app (application_id),
  KEY idx_invite_status (status),
  CONSTRAINT fk_invite_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_opener FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_invite_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_invite_sms FOREIGN KEY (sms_log_id) REFERENCES sms_logs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 3. appointments: additive columns, index, foreign key and check. Existing rows are not rewritten
--    (the generated column is computed from the existing scheduled_at; the other columns take
--    their defaults).
-- ---------------------------------------------------------------
SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'interview_date'),
  'DO 0',
  'ALTER TABLE appointments ADD COLUMN interview_date DATE GENERATED ALWAYS AS (DATE(`scheduled_at`)) STORED AFTER scheduled_at'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'date_only'),
  'DO 0',
  'ALTER TABLE appointments ADD COLUMN date_only TINYINT(1) NOT NULL DEFAULT 0 AFTER interview_date'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'booking_source'),
  'DO 0',
  'ALTER TABLE appointments ADD COLUMN booking_source ENUM(''LEGACY'',''STAFF'',''APPLICANT'') NOT NULL DEFAULT ''LEGACY'' AFTER date_only'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

-- Placed after interview_sms_log_id when that column exists (as in schema.sql); otherwise it is simply
-- added, so a database that never ran migrate_interview_sms_tracking.sql does not fail here.
SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'confirmation_sms_log_id'),
  'DO 0',
  IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'interview_sms_log_id'),
    'ALTER TABLE appointments ADD COLUMN confirmation_sms_log_id INT NULL AFTER interview_sms_log_id',
    'ALTER TABLE appointments ADD COLUMN confirmation_sms_log_id INT NULL'
  )
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appt_interview_date'),
  'DO 0',
  'ALTER TABLE appointments ADD KEY idx_appt_interview_date (interview_date, status)'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appt_confirm_sms'),
  'DO 0',
  'ALTER TABLE appointments ADD KEY idx_appt_confirm_sms (confirmation_sms_log_id)'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND CONSTRAINT_NAME = 'fk_appt_confirm_sms'),
  'DO 0',
  'ALTER TABLE appointments ADD CONSTRAINT fk_appt_confirm_sms FOREIGN KEY (confirmation_sms_log_id) REFERENCES sms_logs(id) ON DELETE SET NULL'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

-- A date-booking row holds its date at 00:00:00. Every existing row has date_only = 0, so the
-- check passes for all of them.
SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND CONSTRAINT_NAME = 'chk_appt_date_only'),
  'DO 0',
  'ALTER TABLE appointments ADD CONSTRAINT chk_appt_date_only CHECK (date_only = 0 OR TIME(`scheduled_at`) = ''00:00:00'')'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;
SET @schema_upgrade_sql = NULL;

-- ---------------------------------------------------------------
-- 4. appointment_events: append-only history, starting when date booking goes live (no back-fill).
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointment_events (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  application_id          INT NOT NULL,
  appointment_id          INT NULL,
  event_type              VARCHAR(30) NOT NULL,
  interview_date          DATE DEFAULT NULL,
  previous_interview_date DATE DEFAULT NULL,
  source                  VARCHAR(20) DEFAULT NULL,
  actor_user_id           INT NULL,
  actor_name              VARCHAR(150) NOT NULL,
  actor_role              VARCHAR(30) NOT NULL,
  reason                  VARCHAR(255) DEFAULT NULL,
  details                 VARCHAR(500) DEFAULT NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aevent_app (application_id, id),
  KEY idx_aevent_appt (appointment_id),
  KEY idx_aevent_date (interview_date, event_type),
  KEY idx_aevent_type (event_type, created_at),
  CONSTRAINT fk_aevent_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_aevent_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
  CONSTRAINT fk_aevent_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Append-only like audit_logs (see migrate_step26_audit.sql). Foreign-key actions never fire
-- triggers, so deleting an account or an application still works. Deliberate DBA maintenance opts
-- in per session: SET @appointment_events_maintenance = 1;
DROP TRIGGER IF EXISTS appointment_events_no_update;
DROP TRIGGER IF EXISTS appointment_events_no_delete;

DELIMITER $$

CREATE TRIGGER appointment_events_no_update BEFORE UPDATE ON appointment_events
FOR EACH ROW
BEGIN
  IF COALESCE(@appointment_events_maintenance, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Appointment event records cannot be changed.';
  END IF;
END$$

CREATE TRIGGER appointment_events_no_delete BEFORE DELETE ON appointment_events
FOR EACH ROW
BEGIN
  IF COALESCE(@appointment_events_maintenance, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Appointment event records cannot be deleted.';
  END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------
-- 5. SMS templates for the date-booking messages. INSERT IGNORE: a row that already exists (for
--    example one an administrator edited) is left exactly as it is; re-running adds nothing.
-- ---------------------------------------------------------------
INSERT IGNORE INTO sms_templates (template_key, label, body) VALUES
('interview_booking_open', 'Interview Booking Opened', 'MSWDO Virac: Your documents for application {ref} are verified. Please log in to your e-SoloParentID account and pick the date you want to arrive for your interview.'),
('interview_date_booked', 'Interview Date Booked', 'MSWDO Virac: Your interview for application {ref} is booked for {when} at {location}. Please come during office hours on that date and bring a valid ID.');

-- ---------------------------------------------------------------
-- ROLLBACK GUIDE (comments only; nothing below is executed).
--
-- First choice: do not drop anything. Turn the applicant date-booking feature off (a setting added in
-- a later stage); the new tables and columns are inert, existing appointments are unaffected, and the
-- staff-scheduled path keeps working.
--
-- Only if the structure itself must be removed, back up first, and run in this order. This never
-- touches a legacy column, so no existing appointment data is lost. It DOES discard the new
-- invitations, events and confirmation-SMS links, so export them first if they matter.
--
--   SET @appointment_events_maintenance = 1;
--   DROP TRIGGER IF EXISTS appointment_events_no_update;
--   DROP TRIGGER IF EXISTS appointment_events_no_delete;
--   DROP TABLE IF EXISTS appointment_events;
--   DROP TABLE IF EXISTS interview_invitations;
--   DROP TABLE IF EXISTS interview_dates;
--   DELETE FROM sms_templates WHERE template_key IN ('interview_booking_open', 'interview_date_booked');   -- optional; sms_logs rows keep their text
--   ALTER TABLE appointments DROP CONSTRAINT chk_appt_date_only;   -- MySQL 8.0.19 and later
--     (MySQL 8.0.16 to 8.0.18 use instead: ALTER TABLE appointments DROP CHECK chk_appt_date_only;)
--   ALTER TABLE appointments DROP FOREIGN KEY fk_appt_confirm_sms;
--   ALTER TABLE appointments DROP KEY idx_appt_confirm_sms;
--   ALTER TABLE appointments DROP KEY idx_appt_interview_date;
--   ALTER TABLE appointments DROP COLUMN confirmation_sms_log_id;
--   ALTER TABLE appointments DROP COLUMN booking_source;
--   ALTER TABLE appointments DROP COLUMN date_only;
--   ALTER TABLE appointments DROP COLUMN interview_date;
--
-- Before dropping date_only / booking_source, any date-only appointment (date_only = 1) would again
-- look like a booking at 12:00 AM; cancel or reschedule those first.
-- ---------------------------------------------------------------
