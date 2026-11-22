# e-SoloParentID: An Online Application and Renewal System with Automated Verification and SMS Notification

Web-based Solo Parent ID application and renewal system for the
**Municipal Social Welfare and Development Office (MSWDO), Municipality of
Virac, Catanduanes, Philippines**.

> **Status (updated 2026-11-21).** The system runs on XAMPP (Apache, PHP 8.2,
> MariaDB 10.4) with real SMS through the IPROG gateway.
>
> **Checked:** PHP lint of every file; 14 database test suites that pass on an
> isolated test instance; four offline suites (62, 156, 31 and 15 checks); a
> dark-mode contrast test of 28 screens at desktop and phone width; and the
> public pages on the running site. Details and the exact commands are in
> section 15.
>
> **Not verified:** a click-through of every screen by every role in a browser,
> load or speed testing, delivery of texts to handsets (the gateway only reports
> that it accepted a message), and the Likert evaluation by respondents (section 16).
>
> **Open items** are listed at the end of section 18. The change log, with dates,
> backups and what was run, is `docs/session-changes.md`.

---

## 1. Project Overview

e-SoloParentID provides an online system for **Solo Parent ID application and
renewal**. Solo Parents in Virac apply online, upload their supporting
documents, track the application, and renew the ID. The system checks each
application automatically and shows the result with plain reasons; MSWDO staff
then verify the documents, schedule the interview date, approve or reject, and
schedule ID release. Solo Parents are informed of the key steps by SMS.

**Document submission is by actual file upload.** Solo Parents submit each
required supporting document as an uploaded file (JPG, PNG or PDF) and replace
it when staff request a correction.

**"Automated" means the checking, not the deciding.** The system recommends;
the MSWD social worker decides every document and every application.

The system supports:

- Online application and renewal.
- Supporting document upload, replacement, and staff verification.
- Automated verification of each application (section 10).
- Interview day booking and ID release scheduling.
- Application status tracking, in the account and on a public lookup page.
- SMS notifications with logs, failure alerts and retry (section 11).

In this document a "Solo Parent" is the person who applies. In the code and
database the same person has the role key `applicant` (folder `applicant/`,
`ApplicantController`, table `applicants`); those names are internal and unchanged.

The system's own wording is **Solo Parent**, **MSWDO staff** and **MSWDO admin** (as labels: MSWDO Staff, MSWDO Admin). In this document "staff"
and "admin" mean MSWDO staff and MSWDO admin. The role keys `admin` and `mswd_social_worker` and the folders `staff/` and `admin/` are internal and unchanged.

## 2. Research Objectives

1. Design and develop the e-SoloParentID system for the MSWDO of Virac,
   Catanduanes.
2. Integrate automated SMS notifications for **application updates, interview
   scheduling, and ID release scheduling**.
3. Evaluate the developed system using the **ISO/IEC 25010:2023** Product
   Quality Model.

Where each objective is met, with evidence, is in `docs/title-defense.md`.

## 3. Technology Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.2 (`composer.json` requires 8.0 or later), no framework; controllers / services / models structure |
| Database | MariaDB 10.4 on XAMPP (the schema is also MySQL 8 compatible), strict SQL mode, prepared statements |
| Web server | Apache (XAMPP) with `.htaccess` protection of non-public folders |
| Front end | Bootstrap 5 served locally from `public/lib/bootstrap` (works offline), JavaScript, project CSS, Filipino/English switch and light/dark option |
| SMS | IPROG SMS API through `services/SMSService.php` (`test` provider logs only) |
| Text recognition | Tesseract OCR, optional, run by `services/AutoVerificationService.php` when installed |
| Scheduling | PHP CLI scripts in `cron/` (Windows Task Scheduler or cron) |
| Testing | PHP scripts in `database/` and Node (`.cjs`) scripts in `scripts/` |

## 4. System Actors and Responsibilities

Roles are stored in `users.role` and enforced server-side on every protected
page (`includes/auth.php`, `config/permissions.php`).

| Actor | Responsibilities |
|---|---|
| **Solo Parent** (role `applicant`) | Register and log in with a username, maintain the profile, apply online, see the automatic check before submitting, upload and replace documents, correct flagged documents, track status, keep or change the interview day, view the ID and its validity, renew the ID. |
| **MSWDO Staff** (`mswd_social_worker`) | Work the application queue, review the automated check, verify documents and request corrections, schedule the interview date, record the interview result, approve or reject, schedule and confirm ID release, record cancellations and missed interviews, retry failed texts, keep application and Solo Parent records. |
| **MSWDO Admin** (`admin`) | User and staff account management, system settings, document requirements (new and renewal), SMS templates and logs, audit log, reports and exports. Does not process applications. |
| **SMS service** | IPROG SMS gateway, called by `SMSService`; every attempt is logged in `sms_logs`. |

Solo Parents can only reach their own records. A deactivated account loses
access on its next request.

## 5. Core System Features

| Feature | Status |
|---|---|
| Public Home Page and public eligibility checking | Implemented |
| Required Documents on the home screen: each document opens a "How to get it" guide, without logging in | Implemented |
| Document verification queue for MSWDO staff: re-uploads marked, dashboard tile, menu badge and an alert while signed in | Implemented |
| Next-step popup after Save Verification (and buttons on the page): correction SMS review, or schedule the interview (date, time, SMS); the forms appear only in the popup, with status lines kept on the page | Implemented |
| Interviews nobody confirmed: staff see an Interviews to Confirm tile, a Confirm who attended list and a menu badge; after 2 weekdays the system reopens the Solo Parent's booking (no SMS, not counted as a miss) | Implemented |
| Registration, login by username, profile, password reset by SMS code | Implemented |
| Online application (personal information, children, category, documents, review) | Implemented |
| Automatic check before submitting (pre-check) | Implemented |
| Document submission by file upload, replacement on correction, validation | Implemented |
| Secure document storage and download (`download.php`) | Implemented |
| Staff document review and verification, with correction requests | Implemented |
| Automated verification card, queue pill and filter for staff | Implemented |
| Approval and rejection | Implemented |
| Application status tracking (account and public reference lookup, limited information) | Implemented |
| Renewal with a short document list per category, an interview (RA 11861 re-check), editable civil status and children ("child with a disability") | Implemented |
| Interview day booking (section 5.1) | Implemented, switched on |
| ID release scheduling, handover confirmation and one-year validity | Implemented |
| SMS notifications, logs, failure alerts, Failed Texts page and retry | Implemented |
| ID-expiry reminder 30 days before expiry (automatic) | Implemented |
| Reports with print and CSV, full CSV export for MSWDO admins | Implemented |
| Audit log (append-only) | Implemented |
| Help page and fixed FAQs | Implemented |
| Filipino/English switch and a light/dark display (dark mode checked for readable text and icons) | Implemented |

### 5.1 Interview day booking

Interviews are booked by **date**; MSWDO staff also set a **time of day** when they schedule, and may choose
any date from today (up to a year ahead) and any time, including right now; office days and
hours are not enforced for staff. The capacity count is per day, and a day the Solo Parent picks
himself or herself has no time. It is switched on
(`interview_date_booking_enabled` = 1 in `system_settings`). How it works:

- **Staff set the first date.** When the documents are verified, staff use the
  "Schedule the interview date" card on the application page, choosing the date and
  the time (any date from today, any time). It is booked right away and the Solo
  Parent is texted the date and time.
- **The Solo Parent may keep it or choose another office day.** Days offered are
  office days (Monday to Friday, not holidays or closure dates set by the
  MSWDO admin), up to 45 days ahead, first come first served, with a daily
  capacity (the `interview_default_capacity` setting, 10 at the time of writing).
- **Nobody confirmed the interview.** A booked interview whose date has passed shows to MSWDO staff as
  "Interviews to Confirm" (dashboard tile, "Confirm who attended" list, menu badge) with Attended and
  Did not attend buttons. If nobody confirms within 2 weekdays the system releases it (also from
  `cron/interview-followup.php`, optional): the Solo Parent's booking reopens and a banner asks them to choose a
  new day. It is not counted toward the missed-interview limit, and no SMS is sent. If the Solo Parent really
  attended, staff can still record the result until they choose a new date.
- **Reschedule allowance.** One reschedule by the Solo Parent by default. A
  cancellation reopens booking while an allowance is left.
- **Cancelling online** is allowed until the office closing time (`appointment_day_end`,
  default 17:00) on the previous office day; after that the Solo Parent contacts MSWDO.
- **Missed interview.** Staff mark a no-show after the date has passed. Booking
  reopens so the Solo Parent can choose a new day, up to **3 misses** (a setting, `interview_missed_limit`, 1 to 10);
  no text is ever sent for it. Solo Parents who missed and have not rebooked for 7 days appear on the staff
  dashboard ("Missed and not rebooked") so staff can call them.
- **Unsuccessful interview.** Approval stays locked. Staff can request corrections, schedule another interview
  (it replaces the old one; the result stays in the history), or reject with a reason.
- **Scheduling warnings.** Staff may pick any date and time; the form asks for confirmation (never blocks) when the
  date is a weekend, a holiday or closure, or the time is outside office hours.
- **Closure and holiday dates** are set by the MSWDO admin; saving a date that
  already has booked interviews is refused.
- Staff do **not** open or close days or invitations by hand any more, and cannot
  grant extra reschedules; those controls were retired (section 18).

Code: `services/InterviewBookingService.php`, `InterviewInvitationService.php`,
`InterviewDateService.php`, `InterviewCalendarService.php`,
`InterviewDateFeature.php` (flag and schema check); tables in section 12.

## 6. Public Home Page and Eligibility Checking

The public Home Page (layout, navigation, Solo Parent ID information, and the
login and registration entry points) and the **public eligibility checker** are
part of the system and are preserved as they are.

Files: `index.php`, `portal.php`, `login.php`, `register.php`,
`eligibility.php`, `views/home/`, `includes/portal/`.

**Required Documents on the home screen.** Each category lists its documents, and every document opens a "How to get it" guide (where to get it, what to prepare, how to request it, fees and official references) without logging in. It is the same content as the signed-in Requirements Guide (`services/RequirementGuideService.php`, `includes/requirement-guides.php`); the 2x2 photo has no guide.

**Public status lookup** (`portal.php?tab=status`) accepts only an application
reference number. It shows the status, the next step, dates (no times), the ID
validity and, for an interview or ID release, the date and the office location.
It shows **no name**, and a rejection appears as "a decision was made, log in to
read it". The page is sent with `noindex` and `no-store` headers. Failed lookups
are limited per IP address (10 per 15 minutes). Reference numbers are
sequential, so the limited status of any reference can still be read; a second
check (the last four digits of the mobile number) is a planned next step.

## 7. Solo Parent Workflow

1. Registration and login. A Solo Parent registers with a full name, a username,
   a password and a mobile number. Sign-in is by **username** and password; email
   and mobile number are contact details, not login identifiers. A text code to
   confirm the mobile number at sign-up is built but switched off
   (`MOBILE_VERIFY_BY_SMS` in `config/auth.php`).
2. Online Solo Parent ID application: personal and children information.
3. Solo Parent category selection (9 active categories; `categories`).
4. **Upload the supporting document files** required for the chosen category
   (`document_requirements`) on the Documents step. Uploading files is the only
   way to submit documents. A file can be replaced before submitting.
5. Document validation (type, real file content, size) when each file is uploaded
   and again at submission.
6. **Automatic check** of the draft before submitting (blurry or dark photo,
   missing or unusual details), with reasons the Solo Parent can fix at once.
7. Submission and reference number. Submission is refused until every required
   document has a valid uploaded file.
8. Status tracking and in-app notifications.
9. Re-upload of documents flagged **Needs Correction** from the Documents page.
10. Interview day: keep the date staff set or choose another (section 5.1);
    cancel while the online deadline is open.
11. **My ID**: release date, handover, validity and renewal window.
12. Renewal (section 10).

**Profile and mobile number.** A Solo Parent who changes their mobile number must
re-enter their **current password**; a changed number is no longer treated as
confirmed. Staff can confirm a number for older accounts
(`users.mobile_verified_at`). An MSWDO admin may create a Solo Parent account.

## 8. MSWDO Staff Workflow

1. Sign-in through the unified login page (`login.php`; `mswd-login.php`
   redirects there).
2. **Applications** queue with status, type and automated-check filters.
3. View the Solo Parent's information, the automated check card and the files
   uploaded.
4. **Document verification:** one Save Verification form records each file as
   Verified or Needs Correction, with remarks the Solo Parent sees. Correction
   texts are previewed and confirmed by staff, not sent automatically.
5. **Schedule the interview date** (section 5.1) and send the text; record the
   interview result (Successful or Unsuccessful).
6. **Approve, reject or request correction.** Approval is refused until the
   required documents are verified and a successful interview is recorded (new
   applications and renewals; only renewals filed before 2026-11-23 as
   "documents only" are exempt). **Reject** (button restored) needs a reason; common
   RA 11861 reasons can be picked and edited. A renewal interview shows a
   **re-check** (not married or living with a partner; a qualifying child; still the
   main caregiver; still in Virac; category still applies; **the Solo Parent showed their
   current Solo Parent ID**, compared with the old ID's reference, name and dates shown on
   the card) — every item must be ticked for a Successful result. If the ID was lost or
   forgotten, staff tick **ID not shown** and give the reason (history and audit log). The
   renewal screen, the Appointment page and the interview SMS remind the Solo Parent to
   bring the current Solo Parent ID and a valid ID (the compact SMS stays one text).
7. **ID release:** schedule the release date and text the Solo Parent. On the day, **Confirm handover**:
   the ID is given only to the Solo Parent in person (a required tick), and staff type the **start date
   and expiration date printed on the card** (pre-filled with today and one year later; a span far from
   one year is questioned). The system counts from those dates. A required document that expired while the
   ID waited does not block the handover, but staff must give a reason (audit-logged). Dates typed wrong
   can be corrected with a reason. Releases whose date has passed show as a dashboard tile, a red pill and
   the Appointment Management badge; the Solo Parent sees "Your ID is ready" in their account (no SMS).
8. **Appointment Management:** today's and upcoming interviews, results to record,
   cancellations (with a reason) and missed interviews. Each text sent shows
   Sent, Failed or Not sent with a Retry button.
9. **Failed Texts** page and menu count: texts that did not go through in the
   last 7 days, with Retry where allowed.
10. Solo Parent and application records, reports, notifications and the staff account page.

Records are browsed from `staff/applicants.php` and processed on
`staff/application.php`.

## 9. MSWDO Admin Responsibilities

- Create, edit, activate/deactivate, unlock, and reset access for user and
  staff accounts (`admin/users.php`, `admin/user_edit.php`).
- System configuration (`admin/settings.php`): office information, document
  limits, SMS settings, ID validity and renewal reminder days, interview days
  (office closing time, closure and holiday dates, daily capacity).
- Required documents per category for a new application and for a renewal
  (`admin/requirements.php`).
- SMS: notification templates and the on/off switch of each kind, the SMS log,
  retry of failed texts and a test send (`admin/sms.php`).
- Audit log, eligibility logs, reports with print and CSV, and a full CSV export
  of all applications or all user accounts (every export is audit-logged).

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
| Needs Correction | The Solo Parent must re-upload; staff remarks are shown |

**Automated verification** (`services/AutoVerificationService.php`). Runs when
the Solo Parent reviews the draft, at submission and when staff open an
application whose result changed. It checks: required documents present (a
document already verified or declared on paper counts), dates and ages and the
children's ages, duplicate files within the application and in other
applications, possible duplicate Solo Parent, contact details, and image quality
(width and height at least 600 pixels, brightness 40 to 250, sharpness at least
100). Where Tesseract is installed, a name from the application must appear in at
least half of the words read from each document (accents ignored, one-letter
tolerance), within a 20 second budget. The result is ready, needs a closer look,
or needs a fix, with a reason for each check, and is saved in
`application_auto_checks`. It never rejects an application or marks a document
verified: it only helps staff and the Solo Parent. It cannot prove a document is
genuine. Tools and the evaluation method: `docs/automated-verification.md`,
`docs/automated-verification-evaluation.md`, `database/auto_verification_report.php`,
`database/auto_verification_samples.php`.

**Renewal.** The renewal form lists only the documents this renewal requires:
the per-category renewal list set in Admin > Requirements
(`migrate_renewal_requirements.sql` set it for the 9 active categories: 1 to 4
documents, against 4 to 8 for a new application) and any previously accepted
document that has expired. One-time proofs (birth, marriage and death
certificates, decrees, police and medical records) are not asked again. Each
document is uploaded as a file through the same validation, held in the private
draft folder until submission, then queued for staff verification. An uploaded
file is **never** treated as verified. **Timing:** the form opens 30 days before the
ID expires (`renewal_reminder_days`) so the Solo Parent can prepare and save it; it
can be **submitted from the expiration date**; and it closes 365 days after expiry
(`renewal_grace_days`), after which a new application is needed. **RA 11861:** a
qualifying child is 22 or younger, or over 22 with a disability that keeps them from
fully taking care of themselves; the Solo Parent may update civil status and children
and tick "With a disability", which adds a **PWD ID or medical certificate** upload. The
automated check flags a child over 22 without the tick, "no qualifying child", and a
civil status changed to Married (warnings only; the interview decides). The proposed
renewal list needs MSWDO confirmation.

**`application_document_checklists` (internal table).** The Solo Parent-facing
checklist submission is retired. The table remains because staff document
review and renewal snapshots read and write it. It is not how documents are
submitted and must be kept.

**Staff verification save.** One **Save Verification** form (`save_verification`
in `StaffController`, `ApplicationReviewService::saveVerification()`) saves the
status and remarks of every edited document in one transaction, keeps the status
history and audit entries, and refuses to save if someone else changed a
document in the meantime.

## 11. Automated SMS Notification Workflow

`services/SMSService.php` sends every message and records each attempt in
`sms_logs` (PENDING, then SENT or FAILED with the gateway response).

**Which texts are sent.** To save SMS credits only the steps that need the
Solo Parent are texted. Each kind has its own switch in Admin > SMS >
Notification Templates.

| Text | How it is sent | Default |
|---|---|---|
| ID expired: **one SMS on the exact expiration date** (the date staff entered from the card); never before, never repeated; a failed SMS is retried the same day; a missed day is listed for staff (**Send now**) | **Automatic**, job `cron/renewal-reminders.php` (hourly) and the staff dashboard | On |
| Interview date set by staff | Staff action (automatic or custom wording) | On |
| Document correction | Staff previews and confirms | On |
| ID release scheduled | Staff action | On |
| Appointment cancelled; interview missed (only when the Solo Parent cannot rebook) | Staff action | On |
| Password reset code | On request, by SMS | On |
| Sign-up confirmation code | At registration | Off |
| Application received, renewal received, status update, interview date booked, appointment reminder, reminder after expiry | Automatic | **Off** |

**Failures.** After a staff action the page shows a green "SMS sent", a red "SMS
failed" with the reason and a **Retry SMS** button, or an amber "SMS not sent" if
SMS is switched off. Failed texts also appear in the application's SMS card, on
the **Failed Texts** page (last 7 days) and in the MSWDO admin's SMS log. A text
is retried at most 3 times (`SMS_MAX_ATTEMPTS`). Reset codes, sign-up codes and
correction texts are never retried from a log (the person requests a new code, or
staff confirm the correction again). Provider acceptance does not prove delivery
to the handset.

Configuration (server environment variables or untracked `config/local.php`;
never in code):

| Setting | Default | Notes |
|---|---|---|
| `SMS_PROVIDER` | `test` | `iprog` sends for real; `test` logs only |
| `SMS_API_KEY` | none | IPROG API token; never displayed, scrubbed from errors |
| `SMS_API_URL` | IPROG messages API | Point to a mock gateway for testing |
| `SMS_TIMEOUT` | `10` | Seconds per call |
| `SMS_MAX_ATTEMPTS` | `3` | Attempts per message including retries |
| `SMS_ALLOWED_NUMBERS` | empty | When set, only these numbers get real texts (saves credits while testing) |
| `SMS_DAILY_LIMIT` | `0` | Most real texts per day; 0 = no limit |
| `SMS_COMPACT_TEXTS` | `false` | Shorter wording so a text costs one credit |

Two switches in `config/auth.php`: `PASSWORD_RESET_BY_SMS` (on) and
`MOBILE_VERIFY_BY_SMS` (off). One token holds a limited number of credits; a text
longer than 160 characters costs more than one.

## 12. Database Structure

`database/schema.sql` is the canonical structure (33 tables); `database/seed.sql`
holds reference data. **Never run either against an existing database**: the
schema file contains `DROP DATABASE IF EXISTS virac_solo_parent`.

| Group | Tables | Notes |
|---|---|---|
| Accounts | `roles`, `users`, `applicants`, `rate_limit_hits` | Core |
| Application | `categories`, `document_requirements`, `applications`, `application_drafts`, `application_children`, `application_documents`, `application_document_checklists`, `document_status_history`, `application_status_history`, `application_decisions`, `application_notes`, `application_auto_checks` | Core. `application_notes` is read only (no screen adds notes); `application_auto_checks` holds the saved automated results |
| Renewal | `renewal_requirements`, `renewal_reminders` | Core |
| Interviews and ID release | `appointments`, `id_release_schedules`, `interview_dates`, `interview_invitations`, `appointment_events` | Core. `appointment_events` is append-only |
| Eligibility | `eligibility_assessments`, `eligibility_result_views` | Core |
| SMS and notifications | `sms_logs`, `sms_templates`, `notifications` | Core |
| Audit and configuration | `audit_logs` (append-only via DB triggers), `system_settings`, `scheduled_job_runs` | Core |
| Help content | `faqs` | Read by the Help page and portal search. No admin editor |
| Historical | `appointment_slots` | Retired slot booking. Two rows remain and two appointments still point to them, so the table is kept |

Four tables of retired features (`chat_messages`, `qr_application_slips`,
`public_announcements`, `system_broadcasts`) were dropped from the live database
on 2026-11-18 after a backup (`database/migrate_drop_unused_tables.sql`).

**Migrations.** The older step-by-step `migrate_*.sql` scripts were moved to an
archive outside the project (their result is already in `schema.sql`). Kept in
`database/`: the recent ones, which are applied to the live database
(`migrate_interview_date_booking.sql`, `migrate_auto_verification.sql`,
`migrate_renewal_requirements.sql`, `migrate_drop_unused_tables.sql`,
`migrate_solo_parent_wording.sql`, `migrate_mswdo_role_wording.sql`) and `migrate_fixed_id_validity.sql` (a test
loads it). None may be bulk-imported.

**Pending (not run):** `database/migrate_drop_mobile_code_columns.php` removes the
three obsolete columns `mobile_code`, `mobile_code_expires_at` and
`mobile_code_attempts` from `users` (they are still there; the application does
not use them). It defaults to a read-only `--check` and is guarded to the isolated
test database; `database/rollback_restore_mobile_code_columns.sql` restores the
column structure only.

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
  Permissions-Policy; Bootstrap is served locally.
- **Public pages:** the status lookup shows no name and no times, is not indexed or
  cached, and is rate limited (section 6).
- **Secrets:** `SMS_API_KEY` and DB credentials from environment or untracked
  `config/local.php`; Apache denies `config/`, `database/`, `storage/`,
  `vendor/`, `.git`, SQL/MD/JSON files.
- **Audit trail:** `audit_logs` is append-only, enforced by database triggers;
  CSV exports are logged.
- **Database tooling safety:** database-backed test scripts fail closed
  unless an isolated test environment is configured; see
  [Database tooling safety](docs/database-tooling-safety.md).

Production recommendations: serve over HTTPS, keep `APP_DEBUG` off, hide
Apache version (`ServerTokens Prod`, `ServerSignature Off`). Change the
default MSWDO admin password immediately after installation. Treat any SMS
token that has been shared or shown on screen as exposed and replace it.

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
4. Sign in as the seeded MSWDO admin (see `database/seed.sql` /
   `database/seed_admin.php`) and change the password immediately. Staff
   accounts are created only by an MSWDO admin.
5. Open `http://localhost/virac-soloparent/index.php`.

Defaults: DB `root` / no password (stock XAMPP). Override by copying
`config/local.example.php` to `config/local.php` (git-ignored); set
`APP_DEBUG` to `true` only while developing. For real texts set
`SMS_PROVIDER` to `iprog` and put the token in `SMS_API_KEY`, then check that SMS
is switched on in Admin > Settings and that the kinds you want are on in Admin >
SMS > Notification Templates.

**Text recognition (optional).** Install Tesseract OCR (the usual Windows folders
are searched, or set `TESSERACT_PATH`). Without it the other automatic checks
still run.

**Scheduled jobs** (CLI only, logged in `scheduled_job_runs`):
`php cron/renewal-reminders.php` (daily; on the live machine a Windows task
"e-SoloParentID ID expiry reminders" runs it at 8:00 AM) and
`php cron/appointment-reminders.php` (hourly; its text is off by default).
Configure with Windows Task Scheduler (`C:\xampp\php\php.exe <path to script>`)
or Linux cron.

**Existing installations:** do not re-import `schema.sql` or run migrations
without a verified backup, a rehearsal on an isolated copy, and approval
(section 17). Migrations may contain hardcoded `USE` statements and do not enforce
the test guard. Further guidance: `docs/database-tooling-safety.md` and
`docs/session-changes.md`.

**Offline presentation.** See `OFFLINE-PRESENTATION.md`.

## 15. Testing and Regression Testing

**Isolated test instance.** Database-backed tests run only against a separate
MariaDB on `127.0.0.1:3307` (database `virac_solo_parent_test`, limited test
accounts), never against `virac_solo_parent`. How to set it up and start it:
`docs/mariadb-test-instance-runbook.md`; the rules are in
[Database tooling safety](docs/database-tooling-safety.md). Required settings:
`APP_ENV=testing`, `DB_NAME`, a limited DB user, `SMS_PROVIDER=test`, a test
storage folder and `TEST_DOCUMENT_ROOT`.

| Script | Purpose |
|---|---|
| `database/tool_safety_guard_checks.php` | No-connection checks of the test-environment guard |
| `database/test_auto_verification.php` | Offline: 62 checks of the automated verification rules and image quality |
| `database/test_login_identifiers_offline.php` | Offline: 156 checks of username login, lockout, account creation and sign-up races |
| `database/test_mobile_verification_offline.php`, `test_sms_notice_offline.php` | Offline: 31 and 15 checks (sign-up code, SMS outcome notice) |
| `database/test_new_application_uploads.php` | New-application uploads: required files, replaced files, rechecks at submission, rollback, staff review |
| `database/test_save_verification.php` | The staff Save Verification form: transactions, history, notices, stale-edit refusal |
| `database/test_simplified_renewal.php`, `test_renewal_requirements.php` | Renewal rules, retained documents, and the per-category renewal list |
| `database/test_renewal_rules.php` | Renewal timing (prepare, submit from expiry, close after 365 days), RA 11861 children and the disability proof, updatable civil status/children, automated flags |
| `database/test_public_tracking.php` | Public status lookup: fields shown, no name, no times, headers |
| `database/test_staff_sms_attention.php` | Failed Texts list, retry safety checks, and the cancel/missed SMS alert |
| `database/test_home_requirement_guides.php` | Home screen: every document opens its "How to get it" guide (real guide text, generic fallback for unknown documents, escaping, no guide for the 2x2 photo) |
| `database/test_document_verification_queue.php` | Staff verification queue: oldest application first, re-uploads marked, tile/badge/alert counts agree |
| `database/test_unconfirmed_interview_release.php` | Unconfirmed interviews: released after the grace weekdays, booking reopened, not a counted miss, no SMS, others left alone |
| `database/test_id_release_flow.php` | ID release: card dates and in-person tick, expired-document override, date correction, one SMS on the exact expiry date (same-day retry, no repeat), missed days for Send now |
| `database/test_staff_any_time_schedule.php` | Staff scheduling: today, right now, a weekend and any hour are accepted; past dates and invalid times refused; the time is saved |
| `database/test_role_account_creation.php`, `test_user_account_actions.php`, `test_optional_applicant_gmail.php` | Account creation and actions |
| `database/test_admin_management_layout.php`, `test_eligibility_history.php` | Admin lists and SMS filters; public eligibility and history |
| `database/test_staff_review_workflow.php` | Whole staff workflow; also runs `appointment_management_checks.php`, `automatic_interview_checks.php` and `checklist_flow_checks.php`. Needs the three interview-booking tables, which the isolated test instance does not have yet (apply `migrate_interview_date_booking.sql` there with an administrator account) |
| `scripts/test-dark-mode-contrast.cjs` | Dark mode: renders every MSWDO staff, MSWDO admin and Solo Parent screen (28) at desktop and phone width and fails if any text (4.5:1) or icon (3:1) is below its contrast limit (needs Playwright and Chrome; no web server or login) |
| `scripts/test-next-step-popup.cjs` | Staff application page: after Save Verification the correction SMS or the schedule form opens as a popup, the banner reopens it, nothing opens when nothing is waiting |
| `scripts/test-*.cjs` | Browser/UI-level checks using `scripts/fixtures/`; not re-run recently |

**Result at the time of writing (2026-11-21):** all of the above pass except
`test_staff_review_workflow.php` (the missing tables) and the other `scripts/test-*.cjs`
browser checks (not re-run). The dark-mode contrast test passes. The change log records each run.

**Regression approach.** Run the targeted test for the module being changed
first, then the broader set. When a feature is retired, archive its test rather
than silently deleting it. Build a risky change in a separate copy of the
project, test it there, and copy it in only when it passes.

## 16. ISO/IEC 25010:2023 Software Quality Evaluation

The study uses the **ISO/IEC 25010:2023** Product Quality Model (2023
terminology: "Interaction capability" rather than the 2011 "Usability", and
"Flexibility" rather than "Portability"). The standard has nine characteristics;
the study rates the five named in its objectives with a questionnaire
(`docs/questionnaires/evaluation-questionnaire.html`, Part 3, five-point Likert
scale, frequency count and weighted mean), plus the System Usability Scale (Part 2).

| # | Characteristic | Rated in the study | Evidence available now |
|---|---|---|---|
| 1 | Functional suitability | Yes (questionnaire A, B) | Working workflow; test suites (section 15) |
| 2 | Performance efficiency | Yes (C) | None measured yet |
| 3 | Compatibility | No | Runs offline in XAMPP; not rated |
| 4 | Interaction capability | Yes (F) and SUS | Questionnaire prepared; results pending |
| 5 | Reliability | Yes (D) | SMS failure handling and retry; tests |
| 6 | Security | Yes (E) | Controls in section 13 |
| 7 | Maintainability | No | Not rated |
| 8 | Flexibility | No | Not rated |
| 9 | Safety | No | Not rated |

The questionnaire is prepared but **no respondent results have been collected**.
Existing tests and the controls in section 13 are supporting evidence, not
evaluation results.

## 17. Pruning Rules and Development Guidelines

**Pruning rules**

- Pruning removes genuinely unused features, dead code, obsolete assets, and
  misleading documentation.
- Do not refactor working workflows merely to modernize or reorganize code.
- Preserve the current architecture and established behavior.
- Do not drop database tables or delete records unless the owner has approved it
  after a verified backup (four unused tables were dropped this way on 2026-11-18).
- Do not remove actual document storage, verification records, audit history,
  or operational records.
- Prefer moving files to an archive outside the project over deleting them.
- Any database migration requires **a backup, a rehearsal on an isolated copy,
  and approval** before it is run on the live database.

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

**Retired** (removed from the application; not part of the current scope).

| Retired feature | Notes |
|---|---|
| Solo Parent-facing checkbox/checklist document submission | Replaced by file uploads. `application_document_checklists` is kept for staff review and renewal snapshots |
| Analytics and monitoring dashboards | Pages and models removed |
| Public news and announcements; announcement broadcasting | Pages, models and tables removed |
| Appointment-slot booking | Retired. `appointment_slots` is kept only as history. Date booking (section 5.1) replaced it |
| Staff controls to open, close and reopen booking invitations and grant extra reschedules | Removed; staff set the date, and a missed interview reopens booking automatically |
| Manual eligibility assessment form and adding case notes | Removed; the one existing note is still shown |
| Admin FAQ editing | Removed. `faqs` is read-only fixed content for the Help page |
| Barangay Distribution reports | Retired |
| Chat assistant; QR slips and QR verification | Retired; their tables were dropped |
| Old staff login method and the redirect pages `admin/login.php`, `staff/login.php`, `staff/chat.php`, `services.php` | Removed; the single `login.php` is used (`mswd-login.php` kept as a redirect) |
| Application-managed database backups | Handled outside the application |

**Kept:** document uploads (the official submission method), downloads,
replacement on correction, document verification and correction tracking,
automated verification, `application_document_checklists`, interview day booking,
ID release scheduling, SMS notifications, logs, failure alerts and retry, renewal
with the short document list, ID-expiry reminders and scheduled jobs,
authentication, role permissions, audit logging, account management, reports and
exports, public eligibility checking, the public status lookup and the Help page.

### Decisions made

- Reports, CSV export, the audit log screens, SMS template editing and the admin
  application and notification pages **remain** in scope.
- In-app notifications stay alongside SMS.
- Language and appearance preferences stay.

### Still open

1. The three obsolete mobile-code columns on `users` are not dropped (section 12).
2. The proposed per-category renewal document list needs MSWDO confirmation
   (photo size, whether a valid government ID is added, whether the old ID must
   be shown). The two old inactive categories (1 and 4) have no renewal list.
3. `test_staff_review_workflow.php` cannot complete until the isolated test
   instance has the three interview-booking tables (section 15).
4. Status-update and "application received" texts are switched off to save SMS
   credits; switch them on in Admin > SMS > Notification Templates if wanted.
5. The public status page can still be browsed by reference number; the planned
   second check is not built (section 6).
6. Some category descriptions in the database show broken characters (for
   example an apostrophe shown as question marks); this predates the current work.
7. The ISO/IEC 25010:2023 questionnaire results have not been collected (section 16).
8. The dark-mode test cannot render the Solo Parent Profile page (it needs a signed-in session); every other screen is covered.
9. The wording change did not touch "MSWD" used on its own (for example "MSWD office") or the page label "Administration".
