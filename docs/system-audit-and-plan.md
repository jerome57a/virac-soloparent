# System audit: actors, unused code, and the cleanup plan

Status: **implemented on 2026-11-18** (decisions D1 to D7 as chosen by the owner; see section 6 of `docs/session-changes.md` for what changed). The findings below describe the system as it was before the cleanup.

## 1. How it was checked, and its limits
Static analysis of the whole project (not the live database or browser): every function and method vs. its callers, every page vs. the links to it,
every view vs. what renders it, every database table, setting, permission, form action and SMS template vs. the code that uses it, plus a request
to sensitive paths on the XAMPP site (status codes only). It cannot prove what happens at runtime, and a method called through a string or a
callback can look unused (two such false alarms were found and ruled out, see 5). The role-by-role click-through in phase 5 is what confirms
that each actor really reaches each screen.

## 2. Do all actors interact with the system?
Yes for the pages and menus that exist: **no link or redirect points to a missing page**, every permission is used, every setting is read, and
every view is rendered by something. The gaps are features that were removed from the interface but left behind in code or the database, or
functions an actor is expected to have but cannot reach.

| Actor | What they can do today | What they cannot do (gap) |
|---|---|---|
| **Applicant** (1 page, 11 sections + public pages) | Register, log in, reset password, profile, apply, upload and correct documents, renewal, choose or cancel an interview date, My ID, notifications, requirements guide, help, eligibility checker, track by reference | Nothing missing. "News and announcements" is mentioned in the Filipino translations only; no such feature exists |
| **MSWD staff** (11 pages) | Queue and application review, verify documents, record interview result, schedule an interview date, schedule and confirm ID release, confirm correction SMS, retry SMS, undo a review, applicants, appointments, reports, notifications, account | Open, close or re-open interview booking, grant an extra reschedule, add case notes, and assess eligibility by hand: the code exists, **no button reaches it** |
| **Administrator** (18 pages) | Users (create, role, reset access, unlock, deactivate), settings and reminder jobs, document requirements, SMS logs, templates, retry and test, audit log, reports, eligibility logs, monitoring views | Manage FAQs or announcements (that management was removed; the code is an empty "FAQ" heading). The full-data CSV export page has **no link** anywhere |

## 3. Findings

### A. Code that nothing calls
| # | What | Where | Evidence | Recommendation |
|---|---|---|---|---|
| A1 | Staff controls for interview booking: `open()`, `reopen()`, `close()`, `grantReschedule()` | `services/InterviewInvitationService.php` (about 90 lines plus helpers) | No controller or view calls them; the page only shows the read-only `panel()` | Remove, or restore the buttons (decision D1) |
| A2 | `InterviewDateFeature::forget()` | `services/InterviewDateFeature.php:229` | Never called | Remove |
| A3 | Manual eligibility actions `assess_eligibility` / `update_eligibility` and `ApplicationReviewService::assessEligibility()` | `controllers/StaffController.php:335`, the service | No form posts them; eligibility is now filled in automatically from the checklist | Remove (update the one test that uses it) |
| A4 | `AuthController::handleStaffLogin()` | `controllers/AuthController.php:316` | The unified login replaced it; only the offline test calls it | Remove with its test cases |
| A5 | `Appointment::upsertStaffScheduled()`, `linkSmsLog()` | `models/Appointment.php:34, 118` | Legacy timed scheduling; called only by tests | Remove after confirming (D2) |
| A6 | Case notes: `ApplicationReviewService::addNote()` and the notes list on the admin view | services, `views/partials/case-record.php` | Staff view has `$showCaseNotes = false`; nobody can add a note, so the list is always empty | Remove or restore (D3) |

### B. Retired features still in the system
| # | What | Evidence | Recommendation |
|---|---|---|---|
| B1 | 4 unused database tables: `qr_application_slips`, `system_broadcasts`, `chat_messages`, `public_announcements` (of 37) | No code mentions them | Remove from `schema.sql`; drop from the live database only after a backup (D4) |
| B2 | Redirect stubs: `admin/login.php`, `staff/login.php`, `staff/chat.php`, `services.php` | 3 to 5 lines each, only redirect | Keep for old bookmarks, or remove (D5). `mswd-login.php` and `index.php` are used, keep |
| B3 | Leftovers of removed FAQ/announcement management | Empty "FAQ" section in `ContentService`; audit filter names "FAQ *" and "System Announcement Sent"; notification type `announcement`; a comment about an "admin announcement form"; translation strings | Tidy the leftovers, or build the feature (D6) |
| B4 | `admin/export_csv.php`, the `data.export` permission and `ReportService::fullExport()` | No button or link reaches it; the Reports page uses a different export | Add a button or remove all three (D7) |
| B5 | Five SMS kinds you switched off (`submitted`, `renewal_submitted`, `interview_date_booked`, `appointment_reminder`, `status_change`) | Switched off in Notification Templates | Keep: they are switches, not dead code |

### C. Clutter and hygiene
| # | What | Detail | Recommendation |
|---|---|---|---|
| C1 | `backups/` inside the web folder | 22 files, including a 551 KB database dump and old code copies. Apache blocks it (403) today, but only through the root `.htaccess` | Move outside the web folder |
| C2 | `storage/feature_backups/` and 35 screenshots in `storage/` | 170 old source copies | Move to an archive folder |
| C3 | 4 images that nothing references | `logo_virac.png`, `solo-parent-icon.png`, `solo-parent01.png`, `tourismcover.jpg` | Verify, then archive |
| C4 | `vendor/` (Composer) | `composer.json` requires only PHP; the app never loads the autoloader | Leave, or remove |

### D. Corrections to things I produced earlier
- The **context diagram** and **Level 1 DFD** list "announcements" and "FAQ and announcement content". There is no announcements feature and no FAQ editing, so
  those labels are wrong. The admin's content flow is document requirements, SMS templates and settings.
- `docs/title-defense.md` and `docs/automated-verification.md` do not mention announcements; no change needed there.

## 4. Ruled out (looked unused but is used)
`ReportService::csvSafe()` (used as a callback for CSV safety), `ApplicationAutoCheck::evaluation()` (used by the evaluation report), all 16 permissions, all 20 settings.

## 5. Plan (nothing is done until you approve it)
Each phase ends with checks. If a check fails, that phase is reverted before the next begins.

| Phase | Work | Risk | Checks |
|---|---|---|---|
| 0. Safety | Full copy of the project folder and a fresh database backup; run all offline suites for a baseline | none | Suites pass (62, 172, 31, 15) |
| 1. Housekeeping | C1 to C3: move backups, old copies, screenshots and unused images to an archive **outside** the web folder (moved, not deleted) | very low | Site pages load; sensitive paths still blocked |
| 2. Dead code | A2 to A5 (and A1, A6 as decided), with their tests updated or removed | low | `php -l`, offline suites, then the database test suites on the test instance |
| 3. Retired features | B1 to B4 as decided: schema file edits, the live tables only after backup, stubs, leftovers | low to medium | Test instance first, then the XAMPP site |
| 4. Documents | Fix the two diagrams (D); update `session-changes.md` and this file | none | Re-render and inspect |
| 5. Click-through | Walk each actor through every page on the test site (applicant, staff, admin) and confirm each menu entry works; record any failure | none | A signed-off checklist per actor |

## 6. Decisions needed from you
| # | Question | My recommendation |
|---|---|---|
| D1 | Interview booking controls (open, reopen, close, grant extra reschedule): remove, or give staff the buttons? | Remove. Staff already set dates themselves, and an applicant re-opens booking after a miss |
| D2 | Remove the legacy timed-scheduling functions? | Remove |
| D3 | Case notes: remove, or give staff an "add note" box again? | Remove (the notes list is always empty now) |
| D4 | Drop the 4 unused tables from the live XAMPP database, or only from `schema.sql`? | Only from `schema.sql` now. Dropping is irreversible and gains nothing |
| D5 | Keep or remove the redirect stubs? | Keep, they are tiny and protect old links |
| D6 | FAQs/announcements: build admin management, or tidy leftovers and leave FAQs fixed? | Tidy and leave FAQs fixed |
| D7 | Full CSV export: add a button on the admin Reports page, or remove it? | Add a button if administrators need full exports; otherwise remove |
