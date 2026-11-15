# e-SoloParentID: An Online Application and Renewal System with Automated Verification and SMS Notification

Web-based Solo Parent ID application and renewal system for the
**Municipal Social Welfare and Development Office (MSWDO), Municipality of
Virac, Catanduanes, Philippines**.

> **Documentation status.** This README describes the approved project scope.
> Statements about the implementation were checked against the repository
> (schema, controllers, services, views, tests). They were not verified by
> running the application or its tests, and no test or evaluation result is
> claimed anywhere in this document.
>
> **Database changes are pending.** Prepared migration and cleanup scripts
> exist in `database/`, but **none of them has been executed** against any
> database as part of this work. They are pending approval and a rehearsal on
> an isolated copy (see section 12 and section 17).

---

## 1. Project Overview

e-SoloParentID provides an online system for **Solo Parent ID application and
renewal**. Residents of Virac apply online, upload their supporting
documents, track the application, and renew the ID. MSWDO staff verify the
documents, assess the application, schedule interviews, approve or reject, and
schedule ID release. Applicants are informed of key steps by SMS.

**Document submission is by actual file upload.** Applicants submit each
required supporting document as an uploaded file (JPG, PNG or PDF) and replace
it when staff request a correction. The old checkbox/checklist-style
submission experience is retired and is not how applicants submit documents.

The system supports:

- Online application and renewal.
- Supporting document upload, replacement, and staff verification.
- Staff assessment and processing.
- Staff-managed interview scheduling and ID release scheduling.
- Application status tracking.
- SMS notifications with operational SMS logs.

## 2. Research Objectives

1. Design and develop the e-SoloParentID system for the MSWDO of Virac,
   Catanduanes.
2. Integrate automated SMS notifications for **application updates, interview
   scheduling, and ID release scheduling**.
3. Evaluate the developed system using the **ISO/IEC 25010:2023** Product
   Quality Model.

## 3. Technology Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.0+ (`composer.json`), no framework; controllers / services / models structure |
| Database | MySQL / MariaDB (XAMPP), strict SQL mode, prepared statements |
| Web server | Apache (XAMPP) with `.htaccess` protection of non-public folders |
| Front end | Bootstrap 5 (CDN with Subresource Integrity), JavaScript, project CSS |
| SMS | Semaphore PH API through `services/SMSService.php` (test mode by default) |
| Scheduling | PHP CLI scripts in `cron/` (Windows Task Scheduler or cron) |
| Testing | PHP scripts in `database/` and Node (`.cjs`) scripts in `scripts/` |

## 4. System Actors and Responsibilities

Roles are stored in `users.role` and enforced server-side on every protected
page (`includes/auth.php`, `config/permissions.php`).

| Actor | Responsibilities |
|---|---|
| **Applicant** (`applicant`) | Register and log in, maintain profile, apply online, upload and replace documents, correct flagged documents, track status, view or cancel their interview appointment, renew the ID. |
| **MSWD Staff** (`mswd_social_worker`) | Manage applications and renewals, view and verify documents, request corrections, assess eligibility, schedule interviews, approve or reject, schedule ID release, maintain application and renewal records. |
| **Administrator** (`admin`) | Essential user and staff account management and necessary system configuration. Does not process applications. |
| **SMS service** | Semaphore PH gateway, called by `SMSService`; every attempt is logged in `sms_logs`. |

Applicants can only reach their own records. A deactivated account loses
access on its next request.

## 5. Core System Features

| Feature | Status |
|---|---|
| Public Home Page | Implemented (preserved) |
| Public eligibility checking | Implemented (preserved) |
| Applicant registration and login | Implemented |
| Applicant profile | Implemented |
| Online application (personal info, children, category, documents, review) | Implemented |
| Supporting document submission by file upload, replacement on correction, and validation (the official submission method) | Implemented |
| Secure document storage and download (`download.php`) | Implemented |
| Staff document review and verification, with correction requests | Implemented |
| Application assessment, approval, rejection | Implemented |
| Application status tracking (dashboard and public reference lookup) | Implemented |
| Renewal (simplified form with required document file uploads) | Implemented |
| Interview scheduling by staff (entered date, time, location) | Implemented |
| Applicant appointment viewing and cancellation | Implemented |
| ID release scheduling | Implemented |
| SMS notifications, SMS logs, and retry of failed messages | Implemented |
| Renewal reminders and scheduled jobs | Implemented |
| Applicant Help page | Implemented |
| Essential applicant, staff, and account management | Implemented |
| Authentication, role permissions, audit logging | Implemented |

## 6. Public Home Page and Eligibility Checking

The public Home Page (layout, navigation, Solo Parent ID information, and the
applicant login/registration entry points) and the **public eligibility
checker** are part of the current system and are preserved as they are.

Files: `index.php`, `services.php`, `portal.php`, `login.php`, `register.php`,
`eligibility.php`, `views/home/`, `includes/portal/`.

## 7. Applicant Workflow

1. Registration and login. A new applicant registers with a mobile number;
   sign-in accepts a legacy username or a **confirmed** mobile number.
   No SMS confirmation code is sent.
2. Online Solo Parent ID application: personal and children information.
3. Solo Parent category selection (9 categories, `categories`).
4. **Upload the actual supporting document files** required for the chosen
   category (`document_requirements`). Uploading files is the only way
   applicants submit documents; ticking off a checklist does not count as
   submission.
5. Document validation (type, real file content, size).
6. Application submission and reference number generation.
7. SMS submission confirmation.
8. Application status tracking and notifications.
9. Replacement (re-upload) of documents flagged **Needs Correction** or
   otherwise requiring resubmission, from the same Documents page.
10. Viewing or cancelling the interview appointment that staff scheduled.
11. Renewal.

For a **new application**, documents are uploaded from the applicant
**Documents** page after the application is submitted; the application form
lists the required documents. Whether this order should change is an open item
(section 18).

For a **renewal**, the required renewal documents are uploaded as files on the
renewal form itself, and the renewal cannot be submitted until every required
document has a valid uploaded file (see section 10). After submission, any
document can be replaced from the Documents page when staff request a
correction.

**Profile and mobile number.** An applicant who changes their mobile number
must re-enter their **current password**; a changed number is no longer
treated as confirmed. Existing legacy accounts whose mobile number was never
confirmed may need **staff confirmation** before the number can be used to sign
in (`users.mobile_verified_at`). They can still sign in with their legacy
username.

Applicants do **not** book or choose an appointment slot; interviews are
scheduled by staff (section 8).

## 8. MSWD Staff Workflow

1. Staff login (`mswd-login.php` / `staff/`).
2. Application management (queue with status and type filters).
3. View applicant information.
4. View and download the files the applicant uploaded.
5. Document verification: review each uploaded file and record its status as
   Verified or Needs Correction, with remarks that the applicant sees.
6. Eligibility assessment.
7. **Interview scheduling by staff**: staff enter the date, time, and
   location. There is no applicant slot booking and no slot dependency.
8. SMS interview notification (including retry of failed messages).
9. Approval, rejection, or request for correction.
10. ID release scheduling and SMS notification.
11. Application and renewal record management.

Applicant and renewal records are browsed from `staff/applicants.php` and
processed on `staff/application.php`. Approval is refused until required
documents are verified, eligibility is explicitly assessed, and the interview
outcome is recorded (simplified renewals are exempt from the interview).

## 9. Administrator Responsibilities

- Create, edit, activate/deactivate, unlock, and reset access for user and
  staff accounts (`admin/users.php`, `admin/user_edit.php`).
- Necessary system configuration: system/office information, document limits,
  SMS settings, ID validity/renewal period, and the required-documents list per
  category (`admin/settings.php`, `admin/requirements.php`).
- Operational SMS log viewing and retry of failed messages.

## 10. Document Upload and Verification

**Upload.** `services/DocumentValidationService.php` accepts JPG, PNG and PDF,
checks the file's actual content (not name or browser type) and size, and
stores files under random names in `storage/uploads/` (one folder per
application, denied by `.htaccess`). Files are delivered only by
`download.php` after an owner-or-staff check, with `nosniff` and `no-store`.
Size and types are configured in System Settings. Replacing a flagged document
resets it to Pending and keeps the status history (`document_status_history`).

**Verification statuses:**

| Status | Meaning |
|---|---|
| Pending | Not yet reviewed |
| Verified | Accepted by staff |
| Needs Correction | Applicant must re-upload; staff remarks shown to the applicant |

**Retained records.** The system keeps document requirements
(`document_requirements`), upload records (`application_documents`), document
history (`document_status_history`), correction requests and remarks, and
verification tracking.

**`application_document_checklists` (internal table).** The applicant-facing
checklist submission experience is retired. The table itself remains because
the current audit found live production reads and writes: it supports staff
document review and renewal snapshots. It is **not** how applicants submit
documents, it has **not** been removed or dropped, and it must be kept. Do not
read its presence as checklist-based submission.

**Staff verification save.** The staff application page has one **Save
Verification** form (`save_verification` in `StaffController`, implemented by
`ApplicationReviewService::saveVerification()`). It saves the status and
remarks of every edited document in one transaction, keeps the per-document
status history and audit entries, and refuses to save if someone else changed
a document in the meantime (expected-status check).

**Renewal uploads.** The renewal form lists only the documents this renewal
requires (category renewal rules and expired replacements); previously
accepted, unexpired documents are retained and not requested again. Each
required document is uploaded as a file through the same validation as every
other applicant upload (`DocumentValidationService`: JPG/PNG/PDF, real file
content, size limit). Files are held in the applicant's private draft folder
until the renewal is submitted, then stored with the renewal and queued for
staff verification (`FOR_VERIFICATION`). Submission is refused while any
required document has no uploaded file or a file was rejected. An uploaded file
is **never** treated as verified: its `application_document_checklists`
snapshot row starts Pending, and staff verify it like any other document.
Retained earlier records keep their original verification history.

## 11. Automated SMS Notification Workflow

`services/SMSService.php` sends every message and records each attempt in
`sms_logs` (PENDING, then SENT or FAILED with the gateway response). Failed
messages can be retried. Password-reset codes are stored only as a hash on the
account, and the SMS log keeps them masked.

| Event | Objective | Status |
|---|---|---|
| Application submission confirmation | Application update | Implemented |
| Application status change (correction requested, approved, rejected) | Application update | Implemented |
| Interview scheduled or rescheduled | Interview scheduling | Implemented (incl. retry) |
| ID release scheduled | ID release scheduling | Implemented (incl. retry) |
| Interview and renewal reminders (`cron/`) | Operations | Implemented |
| Password-reset codes | Authentication | Implemented |

Configuration (server environment variables or untracked `config/local.php`;
never in code):

| Setting | Default | Notes |
|---|---|---|
| `SMS_PROVIDER` | `test` | `semaphore` to send for real; `test` logs only |
| `SMS_API_KEY` | none | Semaphore API key; never displayed, scrubbed from errors |
| `SMS_SENDER_NAME` | `VIRACMSWD` | Must be approved by Semaphore |
| `SMS_API_URL` | Semaphore messages API | Point to a mock gateway for testing |
| `SMS_TIMEOUT` | `10` | Seconds per call |
| `SMS_MAX_ATTEMPTS` | `3` | Attempts per message including retries |

Provider acceptance does not prove delivery to the handset. Real SMS delivery
requires valid Semaphore credentials and an approved sender name; until
configured, the system runs in test mode.

## 12. Database Structure

`database/schema.sql` is the canonical structure; `database/seed.sql` holds
reference data. **Never run either against an existing database**: the schema
file contains `DROP DATABASE IF EXISTS virac_solo_parent`.

Tables defined in `schema.sql`, grouped by purpose:

| Group | Tables | Notes |
|---|---|---|
| Accounts | `roles`, `users`, `applicants`, `rate_limit_hits` | Core |
| Application | `categories`, `document_requirements`, `applications`, `application_drafts`, `application_children`, `application_documents`, `application_document_checklists`, `document_status_history`, `application_status_history`, `application_decisions`, `application_notes` | Core. `application_document_checklists` is live (staff review and renewal snapshots), not an applicant submission method |
| Renewal | `renewal_requirements`, `renewal_reminders` | Core |
| Interview / ID release | `appointments`, `id_release_schedules` | Core. `appointments` holds interviews only; `appointments.slot_id` is historical |
| Eligibility | `eligibility_assessments`, `eligibility_result_views` | Core (staff assessment and public eligibility checking) |
| SMS / notifications | `sms_logs`, `sms_templates`, `notifications`, `system_broadcasts` | `sms_logs` is core. Use of the others: see section 18 |
| Audit / config | `audit_logs` (append-only via DB triggers), `system_settings`, `scheduled_job_runs` | Core |
| Help content | `faqs` | Read by the applicant Help page and portal search. No admin editor |
| Historical (retired features) | `chat_messages`, `qr_application_slips`, `appointment_slots`, `public_announcements` | Structures and records retained; not used by the application |

**A retired feature is not a dropped table.** Retiring a feature removes its
screens and code paths, not its historical database structures or records.

### Pending database changes (NOT executed)

Prepared scripts exist but have **not** been run as part of this work. They
are pending approval and a rehearsal on an isolated copy:

| Script | Purpose |
|---|---|
| `database/migrate_faq_document_uploads.sql` | Replaces the obsolete "book, reschedule or cancel an appointment" FAQ text in existing databases with a description of uploading supporting documents (matched by exact original text; no row deleted). A fresh install already gets the new text from `seed.sql` |
| `database/migrate_drop_mobile_code_columns.php` | Removes the obsolete mobile-code columns from `users` (`mobile_code`, `mobile_code_expires_at`, `mobile_code_attempts`); `mobile_verified_at` and the password-reset columns are preserved. Defaults to a read-only `--check`; guarded to the isolated test database |
| `database/rollback_restore_mobile_code_columns.sql` | Restores the column structure only (values are not recoverable) |

Until these are approved and run, a production database may still contain the
old FAQ wording and the obsolete mobile-code columns. The application does not
depend on either.

Other `database/migrate_*.sql` files are historical upgrade scripts for older
versions and must not be bulk-imported. `migrate_step20_chat.sql` provisions
the retired chat feature and must not be run. See
[Database pruning report](docs/database-pruning-report.md).

## 13. Security and Data Protection

- **Authentication:** bcrypt; account lockout after 5 failed attempts (15
  minutes); per-IP sign-in throttling; SMS password reset with single-use
  hashed codes and request limits.
- **Authorization:** per-page permissions, server-side role checks, owner
  checks on documents, photos, and notifications; denials audit-logged.
- **CSRF** token on every POST; **sessions** HttpOnly, SameSite, strict mode,
  idle/absolute timeouts, account re-checked each request.
- **SQL injection:** bound parameters and real prepared statements.
- **XSS:** output escaped through `e()`.
- **File uploads:** content validation, random names, storage outside public
  access, delivery only via authenticated `download.php`.
- **Security headers** including Content-Security-Policy and
  Permissions-Policy; Bootstrap CDN pinned with SRI.
- **Secrets:** `SMS_API_KEY` and DB credentials from environment or untracked
  `config/local.php`; Apache denies `config/`, `database/`, `storage/`,
  `vendor/`, `.git`, SQL/MD/JSON files.
- **Audit trail:** `audit_logs` is append-only, enforced by database triggers.
- **Database tooling safety:** database-backed test scripts fail closed
  unless an isolated test environment is configured; see
  [Database tooling safety](docs/database-tooling-safety.md).

Production recommendations: serve over HTTPS, keep `APP_DEBUG` off, hide
Apache version (`ServerTokens Prod`, `ServerSignature Off`). Change the
default administrator password immediately after installation.

## 14. Installation and Configuration

**Warning:** `schema.sql` drops and recreates `virac_solo_parent`. Use it
only on a verified fresh, empty development database.

1. Place this folder in `C:\xampp\htdocs\virac-soloparent`.
2. Start Apache and MySQL in XAMPP.
3. Create the structure and reference data (fresh install only):
   ```
   "C:\xampp\mysql\bin\mysql.exe" -u root < database\schema.sql
   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\seed.sql
   ```
4. Sign in as the seeded administrator (see `database/seed.sql` /
   `database/seed_admin.php`) and change the password immediately. Staff
   accounts are created only by an administrator.
5. Open `http://localhost/virac-soloparent/index.php`.

Defaults: DB `root` / no password (stock XAMPP). Override by copying
`config/local.example.php` to `config/local.php` (git-ignored); set
`APP_DEBUG` to `true` only while developing.

**Scheduled jobs** (CLI only, logged in `scheduled_job_runs`):
`php cron/appointment-reminders.php` (hourly) and
`php cron/renewal-reminders.php` (daily). Configure with Windows Task Scheduler
(`C:\xampp\php\php.exe <path to script>`) or Linux cron.

**Existing installations:** do not re-import `schema.sql` or run migrations
without a reviewed deployment plan, a verified backup, an isolated-copy
rehearsal, and separate approval (section 17). Migrations may contain
hardcoded `USE` statements and do not enforce the test guard. Further
guidance: [Codex cloud setup](docs/codex-cloud.md),
[Appointment management](docs/appointment-management.md) (staff-entered
interview scheduling).

## 15. Testing and Regression Testing

Existing test assets (not executed during this documentation task):

| Script | Purpose |
|---|---|
| `database/tool_safety_guard_checks.php` | No-connection checks of the test-environment guard |
| `database/test_staff_review_workflow.php` | Review workflow, shared staff access, one-year ID expiry (disposable DB) |
| `database/test_simplified_renewal.php` | Renewal rules, document retention, history (disposable DB) |
| `database/test_public_tracking.php` | Public status lookup field filtering |
| `database/test_role_account_creation.php` | Account creation |
| `database/test_admin_management_layout.php` | Admin list pagination, SMS filters/retry rules |
| `database/test_eligibility_history.php` | Public eligibility checking and history |
| `database/appointment_management_checks.php`, `automatic_interview_checks.php`, `checklist_flow_checks.php` | Appointment, automatic interview SMS, checklist flow checks |
| `scripts/test-*.cjs` | Browser/UI-level checks using `scripts/fixtures/` |

**Safety rules.** Database tests require process-level settings
(`APP_ENV=testing`, `DB_NAME=virac_solo_parent_test`, a limited test DB user,
`SMS_PROVIDER=test`) and reject any other database. Never point tests at the
production database or real applicant data. See
[Database tooling safety](docs/database-tooling-safety.md).

**Regression approach.** Run the targeted test for the module being changed
first, then the broader set. When a feature is retired, archive its test
rather than silently deleting it.

**Results:** no test results are recorded in this README. Record them only
from an actual run, with date, command, and outcome.

## 16. ISO/IEC 25010:2023 Software Quality Evaluation

e-SoloParentID will be evaluated with the **ISO/IEC 25010:2023** Product
Quality Model (2023 terminology, not the 2011 terms "Usability" and
"Portability").

| # | Characteristic | What it covers for e-SoloParentID | Status |
|---|---|---|---|
| 1 | Functional Suitability | Completeness, correctness, appropriateness of application, verification, scheduling, renewal, and SMS functions | Planned |
| 2 | Performance Efficiency | Response time, resource use, capacity under expected MSWDO load | Planned |
| 3 | Compatibility | Co-existence and interoperability (browsers, devices, Semaphore API, XAMPP) | Planned |
| 4 | Interaction Capability | Ease of use and learning, user error protection, accessibility, inclusivity | Planned |
| 5 | Reliability | Maturity, availability, fault tolerance, recoverability (e.g., SMS failure handling, retries) | Planned |
| 6 | Security | Confidentiality, integrity, non-repudiation, accountability, authenticity | Planned |
| 7 | Maintainability | Modularity, reusability, analysability, modifiability, testability | Planned |
| 8 | Flexibility | Adaptability, scalability, installability, replaceability | Planned |
| 9 | Safety | Avoidance of harm in operation, risk identification, fail-safe behavior | Planned |

No questionnaire, instrument, respondent group, score, or conclusion has been
defined or recorded in this repository. Existing tests and the controls in
section 13 may serve as supporting evidence but are not evaluation results.

## 17. Pruning Rules and Development Guidelines

**Pruning rules**

- Pruning removes genuinely unused features, dead code, obsolete assets, and
  misleading documentation.
- Do not refactor working workflows merely to modernize or reorganize code.
- Preserve the current architecture and established behavior.
- Do not drop historical database tables or delete existing records as part of
  feature pruning.
- Do not remove actual document storage, verification records, audit history,
  or operational records.
- Any database migration requires a **verified isolated test copy, a backup,
  a rehearsal, and separate approval** before production execution.

**Development guidelines**

- Work one module at a time; run the targeted test before broader ones.
- Check dependencies (controllers, services, views, navigation, permissions,
  tests, SMS, cron jobs) before removing anything.
- Keep server-side authorization, CSRF, prepared statements, output escaping,
  and upload validation intact in every change.
- Do not change the public Home Page or eligibility workflow unless a task
  requires it.
- Do not run `schema.sql`, migrations, or sync tools against a database that
  holds real data. Use the isolated test environment for database-backed tests.
- Never put credentials or the SMS API key in code or this README.
- Update this README when the scope or a feature's status changes.

## 18. Approved Scope and Retired Features

**Retired** (removed from the application; not part of the current or intended
scope). Historical database structures and records are retained.

| Retired feature | Notes |
|---|---|
| Applicant-facing checkbox/checklist document submission | Replaced by actual file uploads. The `application_document_checklists` table is retained for live review and renewal snapshots |
| Analytics and monitoring dashboards | Pages and models removed |
| Public News and announcements; system-wide announcement broadcasting | Pages and models removed; `public_announcements` retained as historical data |
| Applicant self-booking and appointment-slot management | Staff schedule interviews directly. `appointment_slots` and `appointments.slot_id` retained as historical |
| Mobile digit-code verification | No code is texted at registration. Obsolete columns still exist until the pending migration (section 12) is approved |
| Admin FAQ editing | `admin/faq.php` and the `Faq` model removed. `faqs` is still read by the Help page and portal search |
| Barangay Distribution reports | Retired |
| Chat assistant | Retired; `chat_messages` retained |
| QR slips and QR verification | Retired; `qr_application_slips` retained |
| Application-managed database backups | Handled outside the application |

**Kept:** document uploads (the official submission method), downloads,
replacement on correction, document verification and correction tracking,
`application_document_checklists` (internal review and renewal snapshots),
staff-entered interview
scheduling, applicant appointment viewing and cancellation, ID release
scheduling, SMS notifications/logs/retry, renewal reminders and scheduled
jobs, authentication, role permissions, audit logging, essential account
management, public eligibility checking, and the applicant Help page.

### Items needing a decision

1. Document upload order: before submission or after submission (as
   implemented now).
2. Whether other report modules (`admin/reports.php`, `staff/reports.php`,
   CSV export) remain in scope now that Barangay Distribution reports are
   retired.
3. Whether the audit-log browser/export screens, SMS template editing, and
   admin application/notification pages remain, or only the underlying
   recording and logs.
4. Whether in-app notifications (`notifications`, `system_broadcasts`) stay
   alongside SMS.
5. Whether language and appearance preferences stay (not touched so far).
6. Timing and approval of the pending database scripts (section 12).
