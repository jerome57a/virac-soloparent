-- PROPOSED, NOT IMPORTED. Synthetic seed for the database virac_solo_parent_test ONLY.
--
-- Run after database/testing/schema_test.sql, into an empty test database, and
-- only after administrator approval. It is not idempotent; do not re-run it.
--
-- Every record is fictional: example.test addresses, 0900-000-xxxx mobile numbers
-- and "Test"/"Fixture" names. No real applicant data, no production data, and no
-- plaintext password appear in this file.
--
-- PASSWORD HASHES ARE NOT IN THIS FILE. The importing administrator supplies two
-- bcrypt hashes as session variables, created from random secrets that are never
-- written down or reused (procedure: docs/database-tooling-safety.md, "Synthetic
-- test data" and "Import procedure"). Both variables must be set in the SAME client
-- session as this file, for example by a one-time, access-restricted file outside
-- the repository that is placed in front of this file in the same input stream.
-- Deleting that file is not secure erasure; see the "Import procedure".
--
--   @test_admin_password_hash : used by the single administrator account
--   @test_user_password_hash  : used by the staff and applicant accounts
--
-- Safety behavior:
--   * A guard block below runs BEFORE the first INSERT. If either variable is
--     NULL or not shaped like a bcrypt hash, or the session is not in a strict
--     SQL mode, the guard raises an error and the client stops. No row has been
--     inserted at that point and no transaction has been started.
--   * The guard checks FORMAT ONLY. It does not prove that a hash corresponds to
--     any known or intended password, or that it was produced by a real bcrypt
--     implementation. The pattern is a $2a$/$2b$/$2y$ prefix, a two-digit cost
--     and then 53 characters of any kind. It does NOT validate exact length: the
--     end anchor ($) can also match just before one trailing newline, so a value
--     with a trailing newline may pass (reasoned from regular-expression
--     behavior, not tested on this server), and the 53 characters are not
--     restricted to the bcrypt alphabet. The administrator must make sure each
--     hash is a single line with no trailing whitespace.
--   * All inserts run inside one transaction that is committed only on the last
--     line. Run the file with the mysql/mariadb command-line client WITHOUT
--     --force, so the client stops at the first error; an error then ends the
--     session and the server rolls the uncommitted transaction back. --force is
--     prohibited as a command-line option AND through any client option file or
--     my.ini (a "force" setting there has the same effect). Do not paste the file
--     into an interactive session, where an error would not end the session and
--     the open transaction would have to be rolled back by hand.
--   * SERVER REQUIREMENT: MariaDB 10.2.7 or later for the whole procedure. The
--     guard uses the DELIMITER command (command-line client) and an anonymous
--     BEGIN NOT ATOMIC block (MariaDB syntax; MariaDB documents compound statements
--     outside stored programs from 10.1, the exact patch release is not confirmed).
--     The schema imported first (schema_test.sql) uses JSON columns; the MariaDB
--     10.2.7 release notes say the JSON data type was introduced there, which is the
--     highest requirement. (An earlier comment here said 10.1.1; that number is
--     unconfirmed and superseded.) This file is not written for MySQL and MySQL
--     compatibility is not claimed. The administrator must verify the installed
--     server version before importing. This has not been executed on this server.
--   * UNVERIFIED (comments only; nothing here has been run): that the [$] bracket
--     expression in the guard's REGEXP matches a literal "$", that SIGNAL is accepted
--     inside an anonymous BEGIN NOT ATOMIC block outside a stored program, that the
--     client stops at the first error, and what exit status it returns, are not
--     established by documentation reviewed and were not tested. Do not rely on this
--     header as proof that the guard or the rollback works; the operator must read the
--     actual client output (docs/database-tooling-safety.md, "Error handling and limits").
--   * STRICT MODE: this file does not change sql_mode. The administrator must
--     verify the effective session sql_mode and establish strict mode for this
--     import session only, without changing any global or server setting
--     (docs/database-tooling-safety.md, "Server version and session sql_mode").
--     The guard stops the run if the session is not strict.
--   * Nothing is inserted into any other database.

USE virac_solo_parent_test;

DELIMITER $$
BEGIN NOT ATOMIC
  IF @test_admin_password_hash IS NULL
     OR @test_admin_password_hash NOT REGEXP '^[$]2[ayb][$][0-9]{2}[$].{53}$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seed_test: @test_admin_password_hash is missing or not a bcrypt hash';
  END IF;
  IF @test_user_password_hash IS NULL
     OR @test_user_password_hash NOT REGEXP '^[$]2[ayb][$][0-9]{2}[$].{53}$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seed_test: @test_user_password_hash is missing or not a bcrypt hash';
  END IF;
  IF @@SESSION.sql_mode NOT LIKE '%STRICT_ALL_TABLES%'
     AND @@SESSION.sql_mode NOT LIKE '%STRICT_TRANS_TABLES%' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seed_test: session sql_mode is not strict';
  END IF;
END$$
DELIMITER ;

START TRANSACTION;

-- ---------------------------------------------------------------
-- Roles (structural reference rows required by users.role foreign key)
-- ---------------------------------------------------------------
INSERT INTO roles (id, role_key, code, name, description) VALUES
(1, 'admin',              'ADMINISTRATOR', 'System Administrator',       'Test fixture role.'),
(2, 'mswd_social_worker', 'MSWD_STAFF',    'MSWD Staff / Social Worker', 'Test fixture role.'),
(3, 'applicant',          'SOLO_PARENT',   'Solo Parent',      'Test fixture role.');

-- ---------------------------------------------------------------
-- Accounts: 1 administrator, 1 staff, 2 applicants (explicit ids keep FKs stable)
-- Both hash variables were validated by the guard block above.
-- ---------------------------------------------------------------
INSERT INTO users (id, full_name, email, username, password_hash, mobile, mobile_verified_at, role) VALUES
(1, 'Test Administrator', 'test.admin@example.test', 'test.admin',
    @test_admin_password_hash, NULL, NULL, 'admin'),
(2, 'Test Staff Reviewer', 'test.staff@example.test', 'test.staff',
    @test_user_password_hash, '09000000002', NOW(), 'mswd_social_worker'),
(3, 'Fixture Applicant One', '', NULL,
    @test_user_password_hash, '09000000003', NOW(), 'applicant'),
(4, 'Fixture Applicant Two', 'fixture.two@example.test', NULL,
    @test_user_password_hash, '09000000004', NOW(), 'applicant');

INSERT INTO applicants (user_id, birthdate, sex, civil_status, barangay, address) VALUES
(3, '1990-01-01', 'Female', 'Single',  'Test Barangay A', '1 Fixture Street, Test Barangay A'),
(4, '1988-02-02', 'Male',   'Widowed', 'Test Barangay B', '2 Fixture Street, Test Barangay B');

-- ---------------------------------------------------------------
-- Solo Parent categories and document requirements (fictional labels)
-- ---------------------------------------------------------------
INSERT INTO categories (id, name, description, sort_order) VALUES
(1, 'TEST Category A', 'Fixture category used by automated tests.', 1),
(2, 'TEST Category B', 'Fixture category used by automated tests.', 2),
(3, 'TEST Category C (inactive)', 'Inactive fixture category.', 3);
UPDATE categories SET is_active = 0 WHERE id = 3;

-- category_id NULL = applies to every category
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES
(NULL, 'brgyCert',  'TEST Barangay certificate',      1, 1),
(NULL, 'birthCert', 'TEST Birth certificate',         1, 2),
(NULL, 'validId',   'TEST Valid ID',                  1, 3),
(NULL, 'photo',     'TEST 2x2 photo',                 1, 4),
(1, 'testAffidavitA', 'TEST Affidavit (category A)',  1, 5),
(1, 'testOptionalA',  'TEST Optional paper (A)',      0, 6),
(2, 'testAffidavitB', 'TEST Affidavit (category B)',  1, 5);

INSERT INTO renewal_requirements (category_id, doc_key, label, always_refresh, sort_order) VALUES
(1, 'brgyCert',  'TEST Fresh barangay certificate', 1, 1),
(1, 'validId',   'TEST Valid ID (reusable)',        0, 2);

-- ---------------------------------------------------------------
-- Applications (no application_documents rows: stored_path would point at files
-- that must not exist in shared storage; tests create their own uploads)
-- ---------------------------------------------------------------
INSERT INTO applications
  (id, reference_no, user_id, type, full_name, barangay, address, mobile, email, birthdate, sex, civil_status,
   emergency_contact_name, emergency_contact_relation, emergency_contact_phone, emergency_contact_address,
   category_id, reviewed_by, status, eligibility_status, consent_at, submitted_at)
VALUES
(1, 'TEST-0001', 3, 'new', 'Fixture Applicant One', 'Test Barangay A', '1 Fixture Street, Test Barangay A',
   '09000000003', '', '1990-01-01', 'Female', 'Single',
   'Fixture Contact', 'Sibling', '09000000013', 'Test Barangay A',
   1, NULL, 'Pending Review', 'Not Assessed', '2026-01-05 09:00:00', '2026-01-05 09:05:00'),
(2, 'TEST-0002', 4, 'new', 'Fixture Applicant Two', 'Test Barangay B', '2 Fixture Street, Test Barangay B',
   '09000000004', 'fixture.two@example.test', '1988-02-02', 'Male', 'Widowed',
   'Fixture Contact', 'Parent', '09000000014', 'Test Barangay B',
   2, 2, 'Under Verification', 'Eligible', '2026-01-06 09:00:00', '2026-01-06 09:05:00');

INSERT INTO application_children (application_id, full_name, birthdate) VALUES
(1, 'Fixture Child One', '2015-05-05'),
(2, 'Fixture Child Two', '2012-06-06');

INSERT INTO application_status_history (application_id, status, remarks, changed_by, changed_by_user_id) VALUES
(1, 'Pending Review',     'Submitted (fixture).',     'Fixture Applicant One', 3),
(2, 'Pending Review',     'Submitted (fixture).',     'Fixture Applicant Two', 4),
(2, 'Under Verification', 'Moved by fixture staff.',  'Test Staff Reviewer',   2);

INSERT INTO application_document_checklists (application_id, doc_key, applicant_has, verification_status) VALUES
(1, 'brgyCert', 1, 'Pending'),
(1, 'validId',  1, 'Pending'),
(2, 'brgyCert', 1, 'Verified');

-- ---------------------------------------------------------------
-- Settings. Values mirror config/settings.php defaults except sms_enabled = 0,
-- so a test run cannot attempt SMS delivery even if SMS_PROVIDER is mis-set.
-- ---------------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value) VALUES
('sms_enabled', '0'),
('notify_staff_new_work', '1'),
('document_max_size_mb', '5'),
('document_allowed_types', 'jpg,png,pdf'),
('appointment_min_notice_hours', '2'),
('id_validity_years', '1'),
('renewal_reminder_days', '30');

-- Minimal SMS templates (test mode only; text is generic and contains no personal data).
INSERT INTO sms_templates (template_key, label, body) VALUES
('status_change',       'Status Change',        'TEST: Application {ref} is now {status}.'),
('password_reset',      'Password Reset Code',  'TEST: reset code {code}, expires in {minutes} minutes.');

COMMIT;
