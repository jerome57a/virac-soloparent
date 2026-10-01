-- e-SoloParentID reference data + initial administrator, for a fresh
-- install. Run right after schema.sql:
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\seed.sql
--
-- Initial login:  username "admin" / password "Admin@Virac2026".
-- CHANGE IT IMMEDIATELY after the first login (Manage Users -> Reset
-- Access issues a new random password, or use Forgot Password).

USE virac_solo_parent;

-- ---------------------------------------------------------------
-- The three system roles
-- ---------------------------------------------------------------
INSERT INTO roles (id, role_key, code, name, description) VALUES
(1, 'admin',              'ADMINISTRATOR', 'System Administrator',       'Manages users, settings, analytics, reports, SMS and audit logs.'),
(2, 'mswd_social_worker', 'MSWD_STAFF',    'MSWD Staff / Social Worker', 'Reviews and decides applications, verifies documents, manages appointments.'),
(3, 'applicant',          'SOLO_PARENT',   'Solo Parent Applicant',      'Applies for and renews their own Solo Parent ID.');

-- ---------------------------------------------------------------
-- Initial administrator (password: Admin@Virac2026 -- change it)
-- ---------------------------------------------------------------
INSERT INTO users (full_name, email, username, password_hash, role) VALUES
('MSWDO Virac Administrator', 'admin@mswdo-virac.local', 'admin', '$2y$10$31MBL39gLWJWynXE5ypA9uuebMudqMX1tX6U8zoSNoSWMKFlwjlka', 'admin');

-- ---------------------------------------------------------------
-- Solo parent categories (RA 8972 / RA 11861, Section 3)
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
-- Standard document requirements (category_id NULL = applies to all)
-- ---------------------------------------------------------------
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES
(NULL, 'brgyCert',  'Barangay Certificate of Residency and Solo Parent Status', 1, 1),
(NULL, 'birthCert', 'PSA Birth Certificate(s) of Children', 1, 2),
(NULL, 'validId',   'Valid Government ID', 1, 3),
(NULL, 'photo',     '2x2 ID Photo', 1, 4);

-- ---------------------------------------------------------------
-- Category-specific document requirements
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

-- ---------------------------------------------------------------
-- Chat Support FAQs
-- ---------------------------------------------------------------
INSERT INTO faqs (question, answer, sort_order) VALUES
('Who can apply for a Solo Parent ID?', 'Any solo parent residing in Virac, Catanduanes who solely provides parental care to a child (below 22, or of any age if the child has a disability) under one of the circumstances in RA 8972 / RA 11861 -- e.g. widowed, abandoned, separated, or an unwed parent. Use the Eligibility Checker if you are unsure.', 1),
('What documents do I need?', 'Standard requirements for everyone: Barangay Certificate of Residency and Solo Parent Status, PSA Birth Certificate(s) of your children, a valid government ID, and a 2x2 ID photo. Depending on your specific circumstance (e.g. death of spouse, annulment), one or two additional documents are required -- these are listed for you automatically once you select your category in the application form.', 2),
('How long does processing take?', 'Once submitted, your application is Pending Review, then moves to Under Verification. If needed, MSWDO may schedule a short interview/assessment. Most applications are resolved within a few weeks, but this can vary depending on document completeness and MSWDO office workload.', 3),
('What if my application needs correction?', 'If a document is unclear or a requirement is missing, your application status will show "For Revision" with the specific remarks from MSWDO staff. Go to My Application or Upload Documents to see what needs fixing and to re-upload the corrected file.', 4),
('How do I renew my Solo Parent ID?', 'Your Solo Parent ID is valid for one year. Once it is close to expiring (or already expired), go to ID Renewal in the menu -- your previous information is pre-filled, and you only need to reconfirm your standard documents and submit.', 5),
('How will I be notified of updates?', 'MSWDO sends an SMS to your registered mobile number whenever your application status changes, an interview is scheduled, or your ID is approved/released. You can also see the full history under Notifications.', 6),
('Where is the MSWDO office?', 'MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes. Office hours are Monday to Friday, 8:00 AM to 5:00 PM.', 7),
('How do I book, reschedule or cancel an appointment?', 'Open Appointment in your dashboard and pick an open slot. You can reschedule or cancel online until a few hours before your appointment (the exact cut-off is shown on the page); after that, please contact the MSWDO office.', 8),
('I lost my application slip. What do I do?', 'Open your slip from My Application and choose "Lost it? Get a new QR". The old slip stops working immediately and you can print or save the new one.', 9),
('I forgot my password.', 'On the login page choose "Forgot password" and enter your username or mobile number. A reset code will be sent to your registered mobile number.', 10),
('Can the online assistant tell me if I am eligible or approved?', 'No. The assistant can explain the process and show where your application is, but only MSWD staff decide eligibility and approval after reviewing your documents. The Eligibility Checker gives a preliminary, non-binding result.', 11);

-- ---------------------------------------------------------------
-- SMS templates (SmsService falls back to built-in text if one is missing)
-- ---------------------------------------------------------------
INSERT INTO sms_templates (template_key, label, body) VALUES
('submitted', 'Application Submitted', 'MSWDO Virac: Your Solo Parent ID application {ref} has been received and is Pending Review. We will text you when its status changes.'),
('status_change', 'Status Change (approval/rejection/correction/etc.)', 'MSWDO Virac: Application {ref} is now {status}. Note: {remarks}'),
('interview_scheduled', 'Interview/Appointment Scheduled', 'MSWDO Virac: An interview for application {ref} has been scheduled on {when} at {location}. Please arrive on time and bring a valid ID.'),
('renewal_submitted', 'Renewal Submitted', 'MSWDO Virac: Your Solo Parent ID renewal request {ref} has been received and is Pending Review. We will text you when its status changes.'),
('renewal_reminder', 'Renewal Reminder (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption.'),
('renewal_expired', 'Renewal Reminder after Expiry (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expired on {when}. Please renew online or visit MSWDO to keep your benefits.'),
('appointment_reminder', 'Appointment Reminder (auto)', 'MSWDO Virac: Reminder -- your appointment for application {ref} is on {when} at {location}. Please arrive on time and bring a valid ID.'),
('appointment_cancelled', 'Appointment Cancelled', 'MSWDO Virac: Your appointment for application {ref} on {when} was cancelled. Reason: {reason}. Please book a new appointment online.'),
('appointment_missed', 'Appointment Missed', 'MSWDO Virac: You missed your appointment for application {ref} on {when}. Please log in and book a new appointment.'),
('document_correction', 'Document Needs Correction', 'MSWDO Virac: Your document "{document}" for application {ref} needs to be re-uploaded. Reason: {remarks}. Please log in and upload a new file under Upload Documents.'),
('password_reset', 'Password Reset Code', 'MSWDO Virac: Your password reset code is {code}. It expires in {minutes} minutes. If you didn''t request this, ignore this message.'),
('mobile_verification', 'Mobile Number Verification Code', 'MSWDO Virac: Your e-SoloParentID verification code is {code}. It expires in {minutes} minutes. Do not share this code with anyone.');
