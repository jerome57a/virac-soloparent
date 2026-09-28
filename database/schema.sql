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
  email         VARCHAR(150) NOT NULL,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  mobile        VARCHAR(20)  DEFAULT NULL,
  role          ENUM('applicant','mswd_social_worker','admin') NOT NULL DEFAULT 'applicant',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
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
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  doc_key        VARCHAR(50) NOT NULL,
  original_name  VARCHAR(255) NOT NULL,
  stored_path    VARCHAR(255) NOT NULL,
  mime           VARCHAR(100) NOT NULL,
  size_bytes     INT NOT NULL,
  uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

CREATE TABLE interviews (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  application_id      INT NOT NULL,
  scheduled_at        DATETIME NOT NULL,
  location            VARCHAR(255) NOT NULL,
  notes               VARCHAR(500) DEFAULT NULL,
  result              VARCHAR(500) DEFAULT NULL,
  result_recorded_at  DATETIME DEFAULT NULL,
  scheduled_by        INT NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_interview_app (application_id),
  CONSTRAINT fk_interview_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_interview_staff FOREIGN KEY (scheduled_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE sms_logs (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  application_id   INT NOT NULL,
  mobile           VARCHAR(20) NOT NULL,
  message          VARCHAR(500) NOT NULL,
  provider         VARCHAR(30) NOT NULL DEFAULT 'none',
  provider_status  VARCHAR(30) NOT NULL DEFAULT 'queued',
  sent_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sms_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
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
