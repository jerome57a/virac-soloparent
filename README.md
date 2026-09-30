# Virac Solo Parent ID Online Portal (e-SoloParentID) — Implementation

PHP 8 + MySQL + Bootstrap 5 implementation of the design handoff in
`../design_handoff_solo_parent_portal/`. Built and tested against XAMPP
(Apache + MariaDB) on Windows.

**Status:** All research objectives implemented and validated — see
**Security notes**, **UI review notes** below for the dedicated review
passes, and the actor sections under **What's implemented** for full
feature coverage per role.

## Setup (XAMPP)

1. Copy (or symlink) this `virac-soloparent/` folder into `C:\xampp\htdocs\`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Import the schema (creates the `virac_solo_parent` database, the 9
   solo-parent categories, and their document requirements):
   ```
   "C:\xampp\mysql\bin\mysql.exe" -u root < database\schema.sql
   ```
   (or use phpMyAdmin → Import → select `database/schema.sql`).
4. Create the first System Administrator account:
   ```
   C:\xampp\php\php.exe database\seed_admin.php
   ```
   This prints a generated admin username/password — log in at
   `/admin/login.php`, then delete `database/seed_admin.php`. Use that
   admin account's **Manage Users** page to create MSWD Social Worker
   accounts (there's no other way to create staff accounts, by design).
5. Visit `http://localhost/virac-soloparent/index.php`.

Default DB credentials (`config/database.php`) are `root` / no password,
matching a stock XAMPP install. Edit that file if yours differs.

## Optional: QR codes on the application slip

The printable slip (`slip.php`) shows a QR code linking to the status
checker when `endroid/qr-code` is installed:
```
composer install
```
Without Composer, the slip still works and simply shows the status-check
URL as text instead of a QR image.

## Optional: real SMS delivery

`includes/SmsService.php` logs every notification to the `sms_logs` table
regardless of configuration. To actually send SMS via Semaphore PH, set
`SMS_API_KEY` in `config/config.php`.

## Automatic renewal reminders

`database/send_renewal_reminders.php` texts every applicant whose released
ID expires within the configured window (default 30 days, editable at
**System Settings** → Renewal Reminders) and hasn't been reminded yet for
that expiry (`renewal_reminders` table, one row per application, so it's
safe to run as often as you like without double-texting anyone). To run it
automatically once a day via Windows Task Scheduler:

1. Task Scheduler → Create Task → Trigger: Daily, any time.
2. Action → Start a program:
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\virac-soloparent\database\send_renewal_reminders.php`

It can also be run manually any time (`C:\xampp\php\php.exe database\send_renewal_reminders.php`,
or the **Send Reminders Now** button on System Settings).

## Automatic appointment reminders

`database/send_appointment_reminders.php` works the same way for upcoming
interviews/appointments: texts the applicant once their scheduled time is
within the configured window (default 24 hours), tracked per-interview via
`interviews.reminder_sent_at` (reset to `NULL` whenever that interview is
rescheduled, so a new time always gets its own fresh reminder). Same setup
as above — add a second Task Scheduler entry pointing at
`database\send_appointment_reminders.php` (hourly is reasonable, given the
default 24-hour window), or trigger it manually from System Settings.

## Actors & access control

The system has three account roles (`users.role`) plus the SMS service,
matching the e-SoloParentID system design:

| Role | Can | Landing page |
|---|---|---|
| `applicant` | Register, apply, upload documents, track status/history, request ID renewal, view interview schedule, view notifications, read announcements | `applicant/dashboard.php` |
| `mswd_social_worker` | Review applications & renewals, validate documents, assess eligibility, manage appointments, approve/reject/request correction, verify by QR, update statuses | `staff/dashboard.php` (processing-focused) |
| `admin` | System-wide monitoring, analytics & reports, user/account management, SMS & notification management, application requirements & system settings, audit logs, database/system monitoring & backup. Can also process applications like MSWD (shares the same queue), but the dashboard steers them toward monitoring, not case-by-case processing | `admin/dashboard.php` (system-management-focused) |

Access control is enforced **server-side** on every protected page via
`require_staff_login()` / `require_admin_login()` in `includes/auth.php` —
never just by hiding a nav link. A deactivated account (`users.is_active`)
loses access on its very next request, even mid-session, not just on next
login. `current_staff()`/`login.php` treat `mswd_social_worker` and `admin`
as the two "staff" roles that can sign in at `/admin/login.php`;
`require_admin_login()` additionally bounces non-admin staff back to their
own dashboard.

Applicants only ever see and act on their own data — every applicant-facing
query filters by the logged-in `user_id`; there is no code path that trusts
a raw `?id=`/`?user_id=` parameter for authorization.

## What's implemented

**Applicant**
- Home, Services list, and the full Solo Parent ID portal (Overview, Apply
  Online, My Applications, Check Status) per the design handoff, plus a
  dedicated fixed-sidebar, mobile-first **Dashboard** (`applicant/dashboard.php`) for
  logged-in applicants covering every stage of the process: Dashboard home,
  Eligibility Checker, Apply, My Application, Upload Documents, Appointment,
  ID Renewal, My Solo Parent ID, News & Announcements, Notifications, Chat
  Support, and Profile.
- The 4-step application (Personal Info → Children → Documents → Review),
  with the in-progress draft and uploaded files kept in the applicant's
  session/temp storage until final submit, at which point real
  `applications` / `application_children` / `application_documents` rows
  are created in one DB transaction.
- **Eligibility Checker** (`applicant/dashboard.php?section=eligibility`, also on the
  public Overview tab): a 3-question guided check with an instant result —
  never the final legal determination, which stays with MSWD.
- **Application status tracker**: each application shows a 6-step visual
  progress bar (`application_stage_info()` in `includes/functions.php`)
  mapped from `applications.status` — Eligibility Check → Application
  Submitted → Documents Under Review → Interview/Assessment → Approved →
  QR/Slip Generated — plus a distinct "Needs Correction" banner (with the
  staff's remarks) when a revision is requested, since that's a detour, not
  a fixed step.
- **Upload Documents**: shows every requirement for the applicant's
  category (required vs. optional), each document's verification status
  (`Pending` / `Verified` / `Needs Correction`) and any staff remarks, and
  lets the applicant upload what's missing or replace a flagged document —
  re-uploading resets that document back to `Pending` for MSWD to recheck.
  Only allowed while the application is still in an editable status.
- **Appointment**: applicant self-service interview/assessment booking
  against slots MSWD opens up (`appointment_slots` table, managed at
  `staff/appointment_slots.php`) — pick an open date/time, or reschedule
  into a different open slot. Staff can still schedule/reschedule directly
  from the application page with no slot at all; both paths write to the
  same `interviews` row, and the review page shows who booked it.
- **ID Renewal**: a real, separate single-step flow (`includes/RenewalSubmit.php`)
  gated on already holding an `ID Released` application — re-confirms the
  standard documents only (not the original qualifying circumstance) and
  creates a real `applications` row with `type = 'renewal'`, which MSWD
  processes through the exact same review page as new applications.
- **My Solo Parent ID**: once released, shows the QR/slip plus a
  Valid/Expired badge computed from `applications.id_expires_at` (set to
  one year past release, per `ID_VALIDITY_YEARS` in `config/config.php`).
- **Chat Support**: a searchable, admin-managed FAQ (`admin/faq.php`,
  `faqs` table) plus MSWDO's office contact details — self-service, no
  live-chat backend.
- **Profile**: view/update full name, email, and mobile; change password;
  upload a profile photo (served access-controlled via `avatar.php`, same
  owner-or-staff pattern as `download.php`).
- Server-side validation mirroring the design handoff's rules (PH mobile
  format, required docs per category, etc.) — never trust the browser.
- Per-category document requirements driven by the `document_requirements`
  table, so staff can add/edit requirements without touching code.
- Uploaded documents stored outside public access (`storage/uploads/`,
  `.htaccess`-denied) and served only via `download.php`, which checks the
  requester is the owning applicant or staff.
- Public reference-number status checker (masks the applicant's name).
- **Notifications**: a real internal notification system (`notifications`
  table, independent of the SMS delivery log) with a persistent read/unread
  state, generated for registration, application/renewal submission,
  appointment confirmation, appointment reminder, correction, approval,
  rejection, and renewal reminder. News & Announcements keeps its own
  session-tracked unread indicator (a different feature, no DB table).

**MSWD Social Worker** (pages under `staff/`, shared with `admin` unless noted)
- Application review queue (`staff/index.php`, filterable by status *and*
  type — New vs. Renewal) and detail page (`staff/application.php`):
  view documents, change status, and the change is recorded in both
  `status_history` (human-readable log) and `applications.reviewed_by`
  (a real FK to the reviewing account).
- **Eligibility Assessment**: MSWD's own eligible/not-eligible determination
  with remarks (`applications.eligibility_status`/`eligibility_remarks`),
  recorded directly on the application review page — distinct from the
  applicant's own non-binding self-check.
- **Interview scheduling**: schedule/reschedule an interview (date, time,
  location, notes) directly from the application page — this also sets
  the application's status to `Interview Scheduled` and sends an SMS.
  After the interview, record its result separately (recording a result
  does not itself change the application status — the social worker still
  makes the actual approve/reject/etc. decision as its own step).
- A dedicated **MSWD Dashboard** (`staff/dashboard.php`): pending / under
  verification / for revision / pending renewal counts (each linking into
  the filtered queue), upcoming interviews, and applications recently
  processed by that specific staff member.
- **Document verification**: per-document status (`Pending` / `Verified` /
  `Needs Correction`) and remarks, set directly from the application review
  page — surfaces immediately on the applicant's Upload Documents page.
- **Appointment Management** (`staff/appointments.php`): today's
  appointments, an upcoming calendar grouped by date, and open-slot
  availability at a glance; **Appointment Slots** (`staff/appointment_slots.php`)
  opens up the date/time blocks (with a capacity) that applicants can
  self-book from — a slot with existing bookings can't be deleted.
- **Applicant Records** (`staff/applicants.php` search/list,
  `staff/applicant_view.php` detail): person-centric view of an applicant's
  profile plus their full new-application and renewal history — each entry
  also shows its appointment (if any), document verification breakdown,
  and latest decision/remarks — separate from the application-centric
  processing queue. Both this and the queue (`staff/index.php`) are
  searchable by name/reference number and filterable by barangay (plus
  status/type on the queue), with pagination on both.
- **QR Verification** (`staff/qr_verify.php`): scan an applicant's QR code
  (camera-based, via the `jsQR` library) or type in a reference number to
  jump straight to that application's review page.
- **Reports** (`staff/reports.php`): daily/monthly/approved/rejected/
  pending/renewal counts.

**System Administrator** (pages under `admin/`)
- **Application Monitoring** (`admin/applications.php` list,
  `admin/application_view.php` read-only detail): the same queue MSWD
  works from, but view-only — no status/document/interview/eligibility
  actions — reflecting that admin's role is oversight, not casework.
- **Manage Users** (`admin/users.php`): create accounts of any role,
  change a user's role, activate/deactivate accounts, **Edit**
  (`admin/user_edit.php` — full name/email/mobile; username is fixed, same
  convention as the applicant's own profile edit), **Reset Access**
  (generates a new random password for an account, shown once), and
  **Activity** (jumps into Audit Logs pre-filtered to that account). Quick
  filters split accounts into System Administrators / MSWD Staff /
  Applicant Accounts. An admin cannot deactivate, change the role of, or
  reset the password of their own account (self-lockout guard on the
  first two) — editing their own info and viewing their own activity are
  both still allowed.
- **Analytics & Reports** (`admin/analytics.php`): total applicants/new/
  renewal/approved/rejected, a 12-month application trend, solo parents by
  barangay, category breakdown, sex/civil-status demographics, IDs
  expiring within 60 days, and appointment statistics (total/today/
  upcoming/completed, self-booked vs. staff-scheduled, and upcoming
  slot-capacity utilization) — plus print and CSV export.
- **SMS Notification Management** (`admin/sms.php`): sent/failed/logged-only
  counts, a searchable/filterable log of every message, and an editor for
  the `sms_templates` each notification is rendered from
  (`{ref}`/`{status}`/`{remarks}`/`{when}`/`{location}` placeholders) —
  `SmsService` falls back to its built-in text if a template is emptied out.
- **System Settings** (`admin/settings.php`): default appointment location,
  default appointment capacity (pre-fills new slots on
  `staff/appointment_slots.php`), document upload max size (enforced live
  by `handle_doc_upload()` across every upload path — apply, renewal, and
  correction re-upload), ID validity period, and the renewal/appointment
  reminder windows — all in `system_settings`, read via `get_setting()`
  with a `config.php`-constant fallback so an unset key never breaks
  anything; includes manual **Send Reminders Now** triggers and history
  for both reminder jobs (see above); plus links to **Application
  Requirements** (`admin/requirements.php` — add/edit/remove
  `document_requirements` per category without touching code) and a
  read-only system configuration summary. Allowed document file types stay
  fixed in `config/config.php` (JPG/PNG/PDF) — not exposed as a setting.
- **Database & System Monitoring** (`admin/monitoring.php`): per-table row
  counts, storage usage, recent activity, and a real **one-click database
  backup** — a pure-PHP export (no `shell_exec`) written to
  `storage/db_backups/`, downloadable or deletable from the same page.
- **CSV Export** (`admin/export_csv.php`): download the full applications
  list or users list as CSV, with no filtering (see **Reporting** below for
  filtered exports).
- **Reporting** (`admin/reports.php`): 8 report types — Application,
  Applicant, Approval, Rejection, Renewal, Barangay Distribution,
  Appointment, and Expiring ID — each with a date-range filter, a search
  box, Print, and a CSV export scoped to the current filter (unlike the
  full-list export above). Approval/Rejection reports pull the actual
  decision date from `status_history`, not the submission date.
- **Manage FAQ** (`admin/faq.php`): create/edit/hide/delete the questions
  and answers shown on the applicant's Chat Support page.
- **Audit Logs** (`admin/audit_log.php`): a read-only log of every sensitive
  action system-wide — login/logout, registration, application/renewal
  submission with a per-document upload entry for each file, document
  verification, correction requests (their own distinct action, not lumped
  into generic status changes), approval, rejection, appointment
  scheduling/booking/slot changes, user creation/edit/role/activation/reset,
  system setting changes, and report/CSV/backup exports — searchable by
  actor/details, filterable by action type and date range. Nothing in the
  UI can delete or edit an entry, and it stays accurate even after an
  account is deleted (actor name/role are snapshotted at write time, not
  live-joined).
- A dedicated **Administrator Dashboard**, distinct from MSWD's: registered
  solo parents, total/renewal applications, active users, IDs expiring
  soon, a system-health indicator, SMS status, and quick links into every
  page above.

**Status model**

`applications.status`: `Pending Review` → `Under Verification` →
(`For Revision` | `Interview Scheduled`) → `Approved` → `ID Released`, or
`Rejected` at any review point. Renewals use the same status set.

## Database

See `database/schema.sql` for the authoritative schema (safe to re-run on
a fresh install; **do not** re-run it against a database with real data —
it drops and recreates everything). Tables: `users`, `categories`,
`document_requirements`, `applications`, `application_children`,
`application_documents`, `status_history`, `interviews`, `appointment_slots`,
`sms_logs`, `sms_templates`, `system_settings`, `renewal_reminders`,
`notifications`, `audit_logs`, `faqs`.

If you already have a live database from before the applicant/admin/MSWD
dashboard features were added, run the additive migrations instead of
re-importing `schema.sql` — they only do `ALTER TABLE`/`CREATE TABLE`, so
existing rows are always untouched. Run them in order, once each:
```
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_applicant_portal.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_admin_staff_dashboards.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_renewal_reminders.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_notifications.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_login_lockout.sql
```
**Never** run `schema.sql` itself against a database with real data —
it hardcodes `DROP DATABASE IF EXISTS virac_solo_parent`, so doing so
destroys every account and application in it. Only ever run the
`migrate_*.sql` files against a live install.

## Storage

- `storage/uploads/` — applicant-submitted documents, one folder per
  application ID, `.htaccess`-denied (served only via `download.php`).
- `storage/avatars/` — applicant profile photos, one file per user,
  `.htaccess`-denied (served only via `avatar.php`, same owner-or-staff
  access check as `download.php`).
- `storage/staff/` — reserved for future staff-related files (e.g.
  internal documents); not yet written to by any code. Inherits the same
  `.htaccess` protection as `storage/uploads/`.
- `storage/db_backups/` — database backups created from **Database &
  System Monitoring** (`admin/monitoring.php`), `.htaccess`-denied (served
  only via `admin/download_backup.php`).

## Security notes

A dedicated review pass checked authentication, authorization, password
security, file upload security, input validation, SQL injection, XSS,
CSRF, session security, sensitive data exposure, and unauthorized record
access. Two real gaps were found and fixed:
- **No brute-force protection on login** — 5 failed attempts (applicant or
  staff, tracked separately per account via `users.failed_login_attempts`/
  `locked_until`) now locks that account out for 15 minutes, even against
  the correct password. Both login forms use the same shared helper
  (`login_lockout_message()`/`record_failed_login()`/`clear_login_lockout()`
  in `includes/functions.php`) so the policy can't drift between them.
- **Session cookie had no `secure` flag** — now set automatically when the
  app is actually served over HTTPS (`config/config.php`), and stays
  exactly as before on a plain-HTTP install like this local XAMPP setup,
  so nothing here breaks local development.

Verified clean, with no changes needed: every POST handler requires a
valid CSRF token; every SQL query is parameterized (no user input is ever
concatenated into query text — only into bound `$params`, with the sole
raw-concatenation exceptions being internally-computed, already-int-cast
values in `LIMIT`/`OFFSET`/`INTERVAL` clauses); every dynamic value in
every template is passed through `e()`; every `admin/*.php` and
`staff/*.php` page enforces its role guard (confirmed live — an MSWD
account is redirected away from every admin-only page); ownership is
checked before any action that could touch another applicant's data
(document upload/download, avatar, slip); uploaded files are re-validated
server-side by real MIME sniffing (not the client-supplied Content-Type),
saved under a randomly generated name outside the web-servable path, and
never trusted by their original name/extension; `password_hash` is never
selected into anything that gets rendered; `SMS_API_KEY` is a server-side
constant, never sent to the browser or displayed (Settings shows only
whether it's configured); the QR code encodes only the reference number
(the same public, name-masked status-check URL), no other personal data.

## UI review notes

A dedicated pass checked navigation, forms, buttons, validation, error/
empty states, confirmation dialogs, accessibility, and design consistency
across every page. One real gap found and fixed: the appointment slot
**Delete** button (`staff/appointment_slots.php`) had no confirmation
dialog, unlike every other destructive action in the app (FAQ delete,
backup delete, requirement delete, password reset all already confirm) —
added, matching the existing pattern exactly.

Everything else checked came back consistent, with nothing needing a
change: every icon-only interactive control has an `aria-label`; every
list/table page has an empty-state message; destructive actions confirm,
reversible ones (activate/deactivate, hide/show) intentionally don't;
mobile responsiveness comes from `.portal-container`'s existing fluid
`clamp()` padding plus Bootstrap's responsive grid, with the one genuinely
wide element (the Reports table) wrapped in `.table-responsive` so it
scrolls horizontally on narrow screens instead of breaking the layout;
every page reuses the established button/card/pill classes rather than
introducing new ones. "Loading states" in the traditional sense mostly
don't apply — this is a server-rendered multi-page app where every action
is a full page load/redirect, not client-side async — except the one
genuinely async interaction (the QR camera scanner), which already shows
live status text through scanning, success, and permission-denied cases.

## Not wired up (needs real credentials/infra, or is a deliberate stub)

- Actual SMS delivery (Semaphore PH/Vonage) — see above; every
  notification is logged regardless.
- Applicant-facing **Settings** and **Help & Support** nav items still show
  a "Coming soon" placeholder (distinct from **Profile** and **Chat
  Support**, which are fully implemented).
- Chat Support is FAQ-only self-service — there's no live two-way chat with
  a staff inbox.
