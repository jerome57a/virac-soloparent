# Virac Solo Parent ID Online Portal (e-SoloParentID) — Implementation

PHP 8 + MySQL + Bootstrap 5 implementation of the design handoff in
`../design_handoff_solo_parent_portal/`. Built and tested against XAMPP
(Apache + MariaDB) on Windows.

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

## Actors & access control

The system has three account roles (`users.role`) plus the SMS service,
matching the e-SoloParentID system design:

| Role | Can | Landing page |
|---|---|---|
| `applicant` | Register, apply, upload documents, track status/history, request ID renewal, view interview schedule, view notifications, read announcements | `dashboard.php` |
| `mswd_social_worker` | Review applications & renewals, validate documents, schedule interviews and record results, approve/reject, update statuses | `admin/dashboard.php` (processing-focused) |
| `admin` | Everything MSWD can **except** process applications, plus manage user accounts/roles/activation, view system-wide reports, export CSV, view the full audit log | `admin/dashboard.php` (stats-focused) |

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
  dedicated fixed-sidebar **Dashboard** (`dashboard.php`) for logged-in
  applicants: application status, upcoming interview, quick links, My
  Application, My Solo Parent ID, My Documents, Notifications, News &
  Announcements.
- The 4-step application (Personal Info → Children → Documents → Review),
  with the in-progress draft and uploaded files kept in the applicant's
  session/temp storage until final submit, at which point real
  `applications` / `application_children` / `application_documents` rows
  are created in one DB transaction.
- **ID Renewal**: a real, separate single-step flow (`includes/RenewalSubmit.php`)
  gated on already holding an `ID Released` application — re-confirms the
  standard documents only (not the original qualifying circumstance) and
  creates a real `applications` row with `type = 'renewal'`, which MSWD
  processes through the exact same review page as new applications.
- Server-side validation mirroring the design handoff's rules (PH mobile
  format, required docs per category, etc.) — never trust the browser.
- Per-category document requirements driven by the `document_requirements`
  table, so staff can add/edit requirements without touching code.
- Uploaded documents stored outside public access (`storage/uploads/`,
  `.htaccess`-denied) and served only via `download.php`, which checks the
  requester is the owning applicant or staff.
- Public reference-number status checker (masks the applicant's name).
- Session-tracked unread indicators for News & Announcements and
  Notifications (the latter reads the real `sms_logs` history).

**MSWD Social Worker**
- Application review queue (`admin/index.php`, filterable by status *and*
  type — New vs. Renewal) and detail page (`admin/application.php`):
  view documents, change status, and the change is recorded in both
  `status_history` (human-readable log) and `applications.reviewed_by`
  (a real FK to the reviewing account).
- **Interview scheduling**: schedule/reschedule an interview (date, time,
  location, notes) directly from the application page — this also sets
  the application's status to `Interview Scheduled` and sends an SMS.
  After the interview, record its result separately (recording a result
  does not itself change the application status — the social worker still
  makes the actual approve/reject/etc. decision as its own step).
- A dedicated **MSWD Dashboard** (`admin/dashboard.php`): pending / under
  verification / for revision / pending renewal counts (each linking into
  the filtered queue), upcoming interviews, and applications recently
  processed by that specific staff member.

**System Administrator**
- **Manage Users** (`admin/users.php`): create accounts of any role,
  change a user's role, activate/deactivate accounts. An admin cannot
  deactivate or change the role of their own account (self-lockout guard).
- **Reports** (`admin/reports.php`): live counts (applicants, active
  users, applications and renewals by status) pulled directly from the
  database — never hardcoded — plus recent system activity.
- **CSV Export** (`admin/export_csv.php`): download the full applications
  list or users list as CSV.
- **Audit Logs** (`admin/audit_log.php`): a read-only, searchable log of
  every sensitive action system-wide — login/logout, registration,
  application/renewal submission, document upload, status changes,
  interview scheduling, user creation/role changes/activation, report
  generation, and CSV export. Nothing in the UI can delete or edit an
  entry.
- A dedicated **Administrator Dashboard**, distinct from MSWD's, focused
  on system-level statistics rather than a processing queue.

**Status model**

`applications.status`: `Pending Review` → `Under Verification` →
(`For Revision` | `Interview Scheduled`) → `Approved` → `ID Released`, or
`Rejected` at any review point. Renewals use the same status set.

## Database

See `database/schema.sql` for the authoritative schema (safe to re-run on
a fresh install; **do not** re-run it against a database with real data —
it drops and recreates everything). Tables: `users`, `categories`,
`document_requirements`, `applications`, `application_children`,
`application_documents`, `status_history`, `interviews`, `sms_logs`,
`audit_logs`.

## Storage

- `storage/uploads/` — applicant-submitted documents, one folder per
  application ID, `.htaccess`-denied (served only via `download.php`).
- `storage/staff/` — reserved for future staff-related files (e.g. staff
  profile photos, internal documents); not yet written to by any code.
  Inherits the same `.htaccess` protection as `storage/uploads/`.

## Not wired up (needs real credentials/infra, or is a deliberate stub)

- Actual SMS delivery (Semaphore PH/Vonage) — see above; every
  notification is logged regardless.
- Applicant-facing **My Profile**, **Settings**, and **Help & Support**
  (dashboard sections exist and are reachable, but intentionally show a
  clean "Coming soon" placeholder rather than fake functionality).
- The renewal-reminder cron job mentioned in the handoff (30 days before
  ID expiry) — no scheduled task ships here.
