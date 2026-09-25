# Virac Solo Parent ID Online Portal — Implementation

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
4. Create the first MSWDO staff account:
   ```
   C:\xampp\php\php.exe database\seed_admin.php
   ```
   This prints a generated admin username/password — log in at
   `/admin/login.php`, then delete `database/seed_admin.php`.
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

## What's implemented

- Home, Services list, and the full Solo Parent ID portal (Overview,
  Apply Online, My Applications, Check Status) per the design handoff.
- Real accounts (`password_hash`/`password_verify`), sessions, and CSRF
  protection on every state-changing form.
- The 4-step application (Personal Info → Children → Documents → Review),
  with the in-progress draft and uploaded files kept in the applicant's
  session/temp storage until final submit, at which point real
  `applications` / `application_children` / `application_documents` rows
  are created in one DB transaction.
- Server-side validation mirroring the design handoff's rules (PH mobile
  format, required docs per category, etc.) — never trust the browser.
- Per-category document requirements driven by the `document_requirements`
  table, so MSWDO can add/edit requirements without touching code.
- Uploaded documents stored outside the web root (`storage/uploads/`,
  `.htaccess`-denied) and served only via `download.php`, which checks the
  requester is the owning applicant or MSWDO staff.
- A minimal MSWDO staff panel (`/admin/`) to review applications, view
  uploaded documents, and change status (writes `status_history` and logs
  an SMS notification).
- Public reference-number status checker (masks the applicant's name).

## Not wired up (needs real credentials/infra, out of scope for local dev)

- Actual SMS delivery (Semaphore PH/Vonage) — see above.
- The renewal-reminder cron job mentioned in the handoff (30 days before
  ID expiry) — the data model supports it (`applications.birthdate`/issue
  date tracking would need a small addition) but no scheduled task ships
  here.
