-- Virac Solo Parent ID Online Portal
-- Schema + seed data. Import via phpMyAdmin or:
--   "C:\xampp\mysql\bin\mysql.exe" -u root -p < schema.sql
--
-- Safe to re-run any time: it drops and recreates the whole database, so
-- running this twice (or importing into a database that already has these
-- tables) won't hit "table already exists" errors. It DOES wipe any data
-- already in virac_solo_parent -- don't re-run this against a database
-- with real applicant data you want to keep.

DROP DATABASE IF EXISTS virac_solo_parent;
CREATE DATABASE virac_solo_parent
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE virac_solo_parent;

-- ---------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(150) NOT NULL,
  profile_photo VARCHAR(255) DEFAULT NULL,
  email         VARCHAR(150) NOT NULL,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  mobile        VARCHAR(20)  DEFAULT NULL,
  role          ENUM('applicant','mswd_social_worker','admin') NOT NULL DEFAULT 'applicant',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_attempts INT NOT NULL DEFAULT 0,
  locked_until  DATETIME DEFAULT NULL,
  password_reset_code VARCHAR(10) DEFAULT NULL,
  password_reset_expires_at DATETIME DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Solo parent categories (RA 8972 / RA 11861, Section 3)
-- ---------------------------------------------------------------
CREATE TABLE categories (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL UNIQUE,
  description VARCHAR(500) DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- doc_key values are stable identifiers referenced by upload fields.
-- category_id = NULL means the requirement applies to every category (standard requirement).
CREATE TABLE document_requirements (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  category_id  INT NULL,
  doc_key      VARCHAR(50)  NOT NULL,
  label        VARCHAR(255) NOT NULL,
  is_required  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_docreq_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  UNIQUE KEY uq_docreq (category_id, doc_key)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Applications
-- ---------------------------------------------------------------
CREATE TABLE applications (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  reference_no  VARCHAR(30) NOT NULL UNIQUE,
  user_id       INT NOT NULL,
  type          ENUM('new','renewal') NOT NULL DEFAULT 'new',
  full_name     VARCHAR(150) NOT NULL,
  barangay      VARCHAR(100) NOT NULL,
  address       VARCHAR(255) NOT NULL,
  mobile        VARCHAR(20)  NOT NULL,
  email         VARCHAR(150) NOT NULL,
  birthdate     DATE NOT NULL,
  sex           ENUM('Male','Female') NOT NULL,
  civil_status  VARCHAR(30) NOT NULL,
  category_id   INT NOT NULL,
  reviewed_by   INT NULL,
  status        ENUM('Pending Review','Under Verification','For Revision','Interview Scheduled','Approved','ID Released','Rejected') NOT NULL DEFAULT 'Pending Review',
  eligibility_status  ENUM('Not Assessed','Eligible','Not Eligible') NOT NULL DEFAULT 'Not Assessed',
  eligibility_remarks VARCHAR(500) DEFAULT NULL,
  id_expires_at DATE NULL,
  consent_at    DATETIME NOT NULL,
  submitted_at  DATETIME NOT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_app_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_app_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE application_children (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  full_name      VARCHAR(150) NOT NULL,
  birthdate      DATE NOT NULL,
  CONSTRAINT fk_child_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE application_documents (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  application_id      INT NOT NULL,
  doc_key             VARCHAR(50) NOT NULL,
  original_name       VARCHAR(255) NOT NULL,
  stored_path         VARCHAR(255) NOT NULL,
  mime                VARCHAR(100) NOT NULL,
  verification_status ENUM('Pending','Verified','Needs Correction') NOT NULL DEFAULT 'Pending',
  staff_remarks       VARCHAR(500) DEFAULT NULL,
  size_bytes          INT NOT NULL,
  uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE status_history (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  status         VARCHAR(30) NOT NULL,
  remarks        VARCHAR(500) DEFAULT NULL,
  changed_by     VARCHAR(150) DEFAULT NULL,
  changed_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hist_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Dates/times MSWD (or admin) opens up for applicant-self-service appointment
-- booking. An interview can instead be scheduled directly by staff with no
-- slot at all (interviews.slot_id NULL) -- slots are an option, not the only path.
CREATE TABLE appointment_slots (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  slot_date   DATE NOT NULL,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  capacity    INT NOT NULL DEFAULT 10,
  created_by  INT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slot (slot_date, start_time),
  CONSTRAINT fk_slot_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE interviews (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  application_id      INT NOT NULL,
  slot_id             INT NULL,
  scheduled_at        DATETIME NOT NULL,
  location            VARCHAR(255) NOT NULL,
  notes               VARCHAR(500) DEFAULT NULL,
  result              VARCHAR(500) DEFAULT NULL,
  result_recorded_at  DATETIME DEFAULT NULL,
  scheduled_by        INT NULL,
  reminder_sent_at    DATETIME DEFAULT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_interview_app (application_id),
  CONSTRAINT fk_interview_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_interview_slot FOREIGN KEY (slot_id) REFERENCES appointment_slots(id) ON DELETE SET NULL,
  CONSTRAINT fk_interview_staff FOREIGN KEY (scheduled_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE sms_logs (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  application_id   INT NULL, -- NULL for account-level SMS not tied to one application (e.g. password reset)
  mobile           VARCHAR(20) NOT NULL,
  message          VARCHAR(500) NOT NULL,
  provider         VARCHAR(30) NOT NULL DEFAULT 'none',
  provider_status  VARCHAR(30) NOT NULL DEFAULT 'queued',
  sent_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sms_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per application once its expiry reminder has gone out, so the
-- scheduled job (run daily) never sends a second reminder for the same
-- id_expires_at -- a renewal creates a brand-new application row with its
-- own id, so that new record is naturally eligible for its own reminder later.
CREATE TABLE renewal_reminders (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  application_id  INT NOT NULL,
  sent_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reminder_app (application_id),
  CONSTRAINT fk_reminder_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The applicant-facing "internal notification system" -- decoupled from
-- sms_logs (which is an SMS delivery audit trail, keyed by application,
-- and can't represent a pre-application event like registration) so every
-- notification has a real user_id and a real read/unread state.
CREATE TABLE notifications (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  user_id                 INT NOT NULL,
  type                    VARCHAR(30) NOT NULL,
  message                 VARCHAR(500) NOT NULL,
  related_application_id  INT NULL,
  is_read                 TINYINT(1) NOT NULL DEFAULT 0,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_app FOREIGN KEY (related_application_id) REFERENCES applications(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- actor_name/actor_role are snapshotted at write time (not just a live join to
-- users) so the log stays accurate even if the account's name/role changes later.
CREATE TABLE audit_logs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NULL,
  actor_name   VARCHAR(150) NOT NULL,
  actor_role   VARCHAR(30) NOT NULL,
  action       VARCHAR(100) NOT NULL,
  entity_type  VARCHAR(50) DEFAULT NULL,
  entity_id    INT DEFAULT NULL,
  details      VARCHAR(500) DEFAULT NULL,
  ip_address   VARCHAR(45) DEFAULT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Chat Support: self-service FAQ (admin-managed)
-- ---------------------------------------------------------------
CREATE TABLE faqs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  question    VARCHAR(255) NOT NULL,
  answer      TEXT NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO faqs (question, answer, sort_order) VALUES
('Who can apply for a Solo Parent ID?', 'Any solo parent residing in Virac, Catanduanes who solely provides parental care to a child (below 22, or of any age if the child has a disability) under one of the circumstances in RA 8972 / RA 11861 -- e.g. widowed, abandoned, separated, or an unwed parent. Use the Eligibility Checker if you are unsure.', 1),
('What documents do I need?', 'Standard requirements for everyone: Barangay Certificate of Residency and Solo Parent Status, PSA Birth Certificate(s) of your children, a valid government ID, and a 2x2 ID photo. Depending on your specific circumstance (e.g. death of spouse, annulment), one or two additional documents are required -- these are listed for you automatically once you select your category in the application form.', 2),
('How long does processing take?', 'Once submitted, your application is Pending Review, then moves to Under Verification. If needed, MSWDO may schedule a short interview/assessment. Most applications are resolved within a few weeks, but this can vary depending on document completeness and MSWDO office workload.', 3),
('What if my application needs correction?', 'If a document is unclear or a requirement is missing, your application status will show "For Revision" with the specific remarks from MSWDO staff. Go to My Application or Upload Documents to see what needs fixing and to re-upload the corrected file.', 4),
('How do I renew my Solo Parent ID?', 'Your Solo Parent ID is valid for one year. Once it is close to expiring (or already expired), go to ID Renewal in the menu -- your previous information is pre-filled, and you only need to reconfirm your standard documents and submit.', 5),
('How will I be notified of updates?', 'MSWDO sends an SMS to your registered mobile number whenever your application status changes, an interview is scheduled, or your ID is approved/released. You can also see the full history under Notifications.', 6),
('Where is the MSWDO office?', 'MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes. Office hours are Monday to Friday, 8:00 AM to 5:00 PM.', 7);

-- ---------------------------------------------------------------
-- SMS notification templates (admin-editable; SmsService falls back to
-- its own built-in text if a template row is missing or emptied out).
-- Placeholders: {ref} {status} {remarks} {when} {location}
-- ---------------------------------------------------------------
CREATE TABLE sms_templates (
  template_key VARCHAR(50) PRIMARY KEY,
  label        VARCHAR(150) NOT NULL,
  body         VARCHAR(500) NOT NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO sms_templates (template_key, label, body) VALUES
('submitted', 'Application Submitted', 'MSWDO Virac: Your Solo Parent ID application {ref} has been received and is Pending Review. We will text you when its status changes.'),
('status_change', 'Status Change (approval/rejection/correction/etc.)', 'MSWDO Virac: Application {ref} is now {status}. Note: {remarks}'),
('interview_scheduled', 'Interview/Appointment Scheduled', 'MSWDO Virac: An interview for application {ref} has been scheduled on {when} at {location}. Please arrive on time and bring a valid ID.'),
('renewal_submitted', 'Renewal Submitted', 'MSWDO Virac: Your Solo Parent ID renewal request {ref} has been received and is Pending Review. We will text you when its status changes.'),
('renewal_reminder', 'Renewal Reminder (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption.'),
('appointment_reminder', 'Appointment Reminder (auto)', 'MSWDO Virac: Reminder -- your appointment for application {ref} is on {when} at {location}. Please arrive on time and bring a valid ID.'),
('password_reset', 'Password Reset Code', 'MSWDO Virac: Your password reset code is {code}. It expires in {minutes} minutes. If you didn''t request this, ignore this message.');

-- ---------------------------------------------------------------
-- Admin-configurable runtime settings (key/value; code falls back to
-- config.php constants if a key is missing).
-- ---------------------------------------------------------------
CREATE TABLE system_settings (
  setting_key   VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Seed: categories
-- ---------------------------------------------------------------
INSERT INTO categories (name, description, sort_order) VALUES
('Deceased spouse', 'The other parent has passed away and you are solely responsible for your child/children.', 1),
('Abandoned or separated (6+ months)', 'Your spouse has been absent for at least six (6) months and you bear sole parental responsibility.', 2),
('Spouse detained or imprisoned (3+ months)', 'Your spouse is serving a sentence and you are the sole caregiver of your child/children.', 3),
('Spouse physically or mentally incapacitated', 'Your spouse is unable to fulfill parental responsibilities due to a certified medical condition.', 4),
('Legal separation, annulment, or nullity of marriage', 'As decreed by a court or church, with you entrusted with the custody of the child/children.', 5),
('Unwed parent', 'An unmarried mother or father who has chosen to keep and raise their child/children.', 6),
('Legal guardian', 'Any person who solely provides parental care and support to a minor child or children.', 7),
('Spouse is an OFW (RA 11861)', 'Spouse is a low-skilled or semi-skilled overseas Filipino worker who has been abroad for an uninterrupted period of 12 months or more.', 8),
('Victim of rape or crimes against chastity', 'A woman who gives birth as a result of rape or similar crimes and keeps and raises the child.', 9);

-- ---------------------------------------------------------------
-- Seed: standard document requirements (category_id NULL = applies to all)
-- ---------------------------------------------------------------
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES
(NULL, 'brgyCert',  'Barangay Certificate of Residency and Solo Parent Status', 1, 1),
(NULL, 'birthCert', 'PSA Birth Certificate(s) of Children', 1, 2),
(NULL, 'validId',   'Valid Government ID', 1, 3),
(NULL, 'photo',     '2x2 ID Photo', 1, 4);

-- ---------------------------------------------------------------
-- Seed: category-specific document requirements
-- ---------------------------------------------------------------
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'deathCert', 'PSA Death Certificate of Spouse', 1, 1 FROM categories WHERE name = 'Deceased spouse';
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'marriageCert', 'PSA Marriage Certificate', 1, 2 FROM categories WHERE name = 'Deceased spouse';

INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'abandonCert', 'Barangay Certificate of Abandonment / De Facto Separation', 1, 1 FROM categories WHERE name = 'Abandoned or separated (6+ months)';
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'soloAffidavit', 'Notarized Affidavit of Solo Parent', 1, 2 FROM categories WHERE name = 'Abandoned or separated (6+ months)';

INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'detentionCert', 'Certificate of Detention or Jail Records', 1, 1 FROM categories WHERE name = 'Spouse detained or imprisoned (3+ months)';

INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'medCertSpouse', 'Medical Certificate of Spouse''s Incapacity (public physician)', 1, 1 FROM categories WHERE name = 'Spouse physically or mentally incapacitated';

INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'courtDecree', 'Court or Church Decree of Legal Separation, Annulment, or Nullity', 1, 1 FROM categories WHERE name = 'Legal separation, annulment, or nullity of marriage';

INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'cenomar', 'PSA CENOMAR (Certificate of No Marriage Record)', 1, 1 FROM categories WHERE name = 'Unwed parent';

INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'guardianship', 'Court Order or Proof of Legal Guardianship', 1, 1 FROM categories WHERE name = 'Legal guardian';
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'soleCareCert', 'Barangay Certificate of Sole Care of the Child', 1, 2 FROM categories WHERE name = 'Legal guardian';

INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'ofwContract', 'Spouse''s OFW Employment Contract or DMW (POEA) Certificate', 1, 1 FROM categories WHERE name = 'Spouse is an OFW (RA 11861)';
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'travelRecord', 'Proof Spouse Has Been Abroad 12+ Months (passport stamps or travel record)', 1, 2 FROM categories WHERE name = 'Spouse is an OFW (RA 11861)';

INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT id, 'crimeRecord', 'Police Report, Medico-Legal Report, or Court Record', 1, 1 FROM categories WHERE name = 'Victim of rape or crimes against chastity';
