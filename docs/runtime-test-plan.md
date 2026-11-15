# Isolated runtime test plan (scope-pruning changes)

Status: **prepared, not executed.** Nothing here has been run against a database, SMS provider or storage.

## 0. Preconditions (must be independently verified before any step runs)

- A separate MySQL instance or database that is **not** `virac_solo_parent`. Use the guards in `docs/database-tooling-safety.md`: `APP_ENV=testing`, `DB_NAME=virac_solo_parent_test`, a DB user limited to that database, `SMS_PROVIDER=test`. Run `php database/tool_safety_guard_checks.php` first (it performs no SQL).
- A throwaway `STORAGE_PATH` created for the test run (empty directory, synthetic files only). The real uploads directory is never mounted or pointed to.
- No Semaphore key in the environment. `SMSService::testMode()` must be true; every `sms_logs` row must have `test_mode = 1`.
- A scratch copy of the app served on a different port or virtual host, with `config/local.php` pointing only to the test values.
- Synthetic accounts only (applicant, two applicants for ownership checks, staff with `applications.process`, administrator).

Existing suites that apply, run only after the preconditions above: `database/test_staff_review_workflow.php`, which loads `automatic_interview_checks.php` and `appointment_management_checks.php`; `database/checklist_flow_checks.php`; `database/test_simplified_renewal.php`; `database/test_new_application_uploads.php` (writes synthetic files under `STORAGE_PATH`, which the guard does not redirect: verify storage isolation first); `database/test_role_account_creation.php`. `database/test_user_account_actions.php` is part of the deferred account-management work and is not yet committed.

## 1. Applicant mobile change (profile)

| # | Case | Expected |
|---|------|----------|
| 1.1 | Change mobile with the correct current password | Saved immediately, no SMS, `mobile_verified_at` set |
| 1.2 | Change mobile with a wrong password | Rejected with "Enter your current password…", number unchanged, `Mobile Change Refused` audit row. The code has no lockout on this form; record whether repeated attempts are throttled |
| 1.3 | Change mobile with an empty password | Rejected (same message) |
| 1.4 | Submit the profile with the same number (`09…` vs `+63…` form) | Unchanged; no password required; `mobile_verified_at` kept |
| 1.5 | Invalid or duplicate mobile format | Validation error |
| 1.6 | Legacy applicant (username, unconfirmed or NULL `mobile_verified_at`) signs in with username and password | Succeeds |
| 1.7 | Same legacy applicant tries sign-in by mobile number | Refused until an administrator confirms the number |
| 1.8 | Administrator edits an applicant's number | `mobile_verified_at` cleared; admin page shows "not confirmed"; **Confirm Mobile** sets it; mobile sign-in then works |
| 1.9 | Admin SMS template list | No "Mobile Number Verification Code" row; old `sms_templates` row and old `sms_logs` rows still in the database |
| 1.10 | `mobile_verification` key | `SMSService::TEMPLATES`, the placeholder list and `ALWAYS_ON_TEMPLATES` no longer contain it |

## 2. Interview scheduling (slotless)

Automated by `automatic_interview_checks.php`, `appointment_management_checks.php` and `checklist_flow_checks.php`. Manual browser pass in addition:

| # | Case | Expected |
|---|------|----------|
| 2.1 | Staff enter a valid future office date/time/location | BOOKED, status "Interview Scheduled", `slot_id` NULL, test-mode SMS logged, applicant notified |
| 2.2 | Malformed date, missing time, past date, a time before opening or after closing, empty or over-long location | Inline error, no appointment row |
| 2.3 | Weekend, national or local holiday, extra holiday, office closure | Rejected |
| 2.4 | Two applicants at the same time | Both accepted (no capacity) |
| 2.5 | Reschedule an existing appointment | Same row updated, `slot_id` NULL, reminder reset, one new SMS |
| 2.6 | Repeat an identical save (double click, two connections) | No duplicate appointment or SMS |
| 2.7 | Legacy appointment with a `slot_id` | Displays correctly; rescheduling clears `slot_id`; `appointment_slots` rows untouched |
| 2.8 | Applicant cancels outside the minimum notice window | Cancelled, status restored, staff notified |
| 2.9 | Applicant cancels inside the notice window or a second time | Refused |
| 2.10 | Staff cancel without a reason | Refused; with a reason: cancelled, SMS + in-app notice |
| 2.11 | Mark missed before the time / after the time / twice | Refused / accepted / refused |
| 2.12 | Record result before the time / after / twice | Refused / COMPLETED, no automatic approval / idempotent |
| 2.13 | Reminders (`sendDueReminders`) | One per appointment inside the window; none outside; reset on reschedule |
| 2.14 | SMS retry for a FAILED interview SMS (provider forced to fail, then test mode) | Only the current failed log can be retried; disabled template blocks the retry; no live send |
| 2.15 | Renewal that uses simplified review | Interview scheduling refused |
| 2.16 | Removed UI | No `slot_id` field, **Manage Slots**, or applicant booking page in any role's view |

## 3. Document download (`download.php`)

Use synthetic files created inside the throwaway storage.

| # | Case | Expected |
|---|------|----------|
| 3.1 | Anonymous request | 403 |
| 3.2 | Owner applicant, valid doc id | 200, correct body, `nosniff`, `Cache-Control: private, no-store` |
| 3.3 | Another applicant requests it | 404 (same as a missing document) |
| 3.4 | Staff with `applications.process` | 200, one `Document Viewed` audit row per document per session |
| 3.5 | Administrator (no `applications.process`) | Denied |
| 3.6 | Staff role without the permission | Denied |
| 3.7 | Non-numeric, zero or unknown `doc_id` | 404 |
| 3.8 | DB row whose file is missing | 404 "File is missing from storage." |
| 3.9 | `stored_path` containing `../` or an absolute path (test rows only) | 404 |
| 3.10 | Symlink inside storage pointing outside it (Windows needs developer mode or elevation to create) | 404, and the target is not served |
| 3.11 | Sibling-prefix directory (`storage_evil/…` next to `storage`) | 404 (the check adds a directory separator) |
| 3.12 | MIME jpeg/png/pdf | Inline with the right type; `?download=1` gives attachment |
| 3.13 | Any other stored MIME, for example `text/html` or `image/svg+xml` | `application/octet-stream`, attachment, no execution |
| 3.14 | Filename with quotes, CR/LF, non-ASCII or path separators | Header not injectable; ASCII fallback plus `filename*` |

## 4. Browser navigation and core workflows

Test in Chrome and a second browser. Check desktop and phone widths, and the browser console and network tabs.

- Public Home Page: sidebar, language, login and register links, tracking page. There must be no links to removed pages (Analytics, News, FAQ, Chat, QR, slots).
- Applicant: register, sign in (username or confirmed mobile), dashboard, new application (all steps, uploading a file for every required document; submission must be refused while one is missing, invalid or replaced by a bad file), submit (submission SMS only), tracking, renewal, profile and mobile change, sign out.
- Staff: queue, open an application, review and verify each uploaded file, schedule interview, record result, eligibility, status change, ID release.
- Administrator: users (create, reset access, confirm mobile), settings (appointment days, holidays, closures), SMS logs and templates, audit log. No retired menu entries.
- Renewal: simplified renewal (no interview) and full renewal.
- Eligibility workflow, Home Page and eligibility views are frozen: only confirm that they still load.

## 5. Pass criteria and evidence

- All PHP suites exit 0, with `php -l` clean on the changed files.
- `sms_logs` shows `test_mode = 0` for zero rows.
- The real database and real storage directory are untouched. Record the test DB name and the storage path used.
- Record results per row above (pass/fail, who ran it, date) in the test run notes. Do not paste credentials, hashes or applicant data into them.

## 6. Out of scope for this plan

Provider-side Semaphore delivery (needs live credentials, which are not used), load testing, and production data migration.
