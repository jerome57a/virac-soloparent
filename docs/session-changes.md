# Changes made during the automated-verification work

The XAMPP site (Apache, port 80) and the isolated test site (PHP server, port 8000) serve the same project folder, so everything below is
live on both. Only the database differs: the test site uses `virac_solo_parent_test` on port 3307, XAMPP uses `virac_solo_parent` on port 3306.

## 1. Automated verification (the feature)
- `services/AutoVerificationService.php`: the rules, image checks and OCR name matching (Tesseract is optional).
- `models/ApplicationAutoCheck.php`: saved results. `database/migrate_auto_verification.sql` creates `application_auto_checks`
  (applied to both databases; also added to `database/schema.sql` and `database/testing/schema_test.sql`).
- Staff: `controllers/StaffController.php`, `views/staff/applications/auto-check.php`, `views/staff/applications/show.php`
  (the card), `views/staff/applications/index.php` and `models/Application.php` (the Auto-check pill and filter).
- Applicant: `views/applicant/application/review.php` and `pre-check.php` (the check before submitting).
- Submission hooks: `services/ApplicationService.php`, `services/RenewalService.php` (save the result at submission).
- Styles: the `.ac-*` rules at the end of `public/css/style.css`. Strings: `public/js/translations.js`.
- Tests and tools: `database/test_auto_verification.php`, `database/auto_verification_samples.php`, `database/auto_verification_report.php`.
- Docs: `docs/automated-verification.md`, `docs/automated-verification-evaluation.md`, `docs/title-defense.md`,
  `docs/questionnaires/evaluation-questionnaire.html`, `docs/diagrams/` (context diagram and level 1 DFD).

## 2. Other changes that are also live on both sites (not part of the verification)
| Area | What changed | Files |
|---|---|---|
| Sign-up code | Texts a 6-digit code before the account is created. **Switched OFF** (`MOBILE_VERIFY_BY_SMS = false` in `config/auth.php`). | `controllers/AuthController.php`, `services/AuthService.php`, `services/SMSService.php`, `services/RateLimiter.php`, `views/auth/verify-mobile.php`, `views/auth/login.php`, `login.php`, `register.php` |
| Forgot password | Lockout from wrong reset codes only counts while a real code exists. The switch `PASSWORD_RESET_BY_SMS` is on, the earlier behaviour. | `controllers/AuthController.php`, `services/AuthService.php`, `config/auth.php`, `views/auth/forgot-password.php` |
| Applicant profile | Redesigned layout, an "Action required" banner, password result shown beside the form, live "passwords match" check. | `views/applicant/profile.php`, `controllers/ApplicantController.php`, `public/css/applicant-account.css` |
| Missed interviews | A staff-marked miss reopens booking (up to 5 misses) and no SMS is sent while the applicant can rebook. | `services/InterviewInvitationService.php`, `services/AppointmentService.php`, `models/AppointmentEvent.php`, `views/applicant/appointments/index.php` |
| ID release | New "ID release date passed" list for staff; a note for the applicant. | `services/IdReleaseService.php`, `controllers/StaffController.php`, `views/staff/appointments/index.php`, `views/applicant/my-id.php` |
| SMS wording and log | "Test mode" wording removed from staff/admin screens; the Sent filter counts all sent; reasons shown when a failed message cannot be retried. | `views/staff/applications/show.php`, `views/admin/sms/index.php`, `views/admin/dashboard.php`, `views/admin/settings/index.php`, `models/SmsLog.php`, `controllers/AdminController.php`, `controllers/StaffController.php`, `services/ApplicationReviewService.php`, `services/IdReleaseService.php` |
| Apply wizard | The text arrows on the Back/Continue buttons are now icons (same in every font, kept when the language changes); validation errors are red in both themes. | `views/applicant/application/step-children.php`, `step-documents.php`, `step-personal.php`, `public/css/style.css`, `public/css/dashboard.css`, `public/js/translations.js` |
| SMS alerts for staff | Scheduling an interview now reports what really happened to the text (before, it always said "texted"). Sent/failed/not-sent alerts with a Retry button and an SMS card on each application. (The dashboard alert for failed texts was removed; failed texts stay in Admin > SMS > Logs and on each application.) | `controllers/StaffController.php`, `services/InterviewBookingService.php`, `services/IdReleaseService.php`, `services/ApplicationReviewService.php`, `services/SMSService.php`, `models/SmsLog.php`, `includes/functions.php`, `views/staff/applications/sms-notice.php`, `sms-log.php`, `public/css/style.css` |

## 3. Switches
- `MOBILE_VERIFY_BY_SMS` (`config/auth.php`): false now. Set true to require the code at sign-up.
- `PASSWORD_RESET_BY_SMS` (`config/auth.php`): true (SMS reset code).
- `SMS_PROVIDER` (`config/local.php`): `iprog` on the XAMPP site now (real texts are sent). Set it back to `test` to log texts without sending.

## 4. Database
- One new table, `application_auto_checks`. To remove it: `DROP TABLE application_auto_checks;` (nothing else depends on it).
- A backup of the XAMPP database taken before the change: `C:\dev\backups\virac_solo_parent-before-auto-checks-20261118-030241.sql`.

## 5. SMS: which texts are automatic (decided 2026-11-18)
- **Texts are sent only when staff send them** (interview date, interview booking opened, document correction, ID release schedule, cancellations),
  plus the reset code, **and the automatic ID-expiry reminder**.
- **Switched OFF in Admin > SMS > Notification Templates** (XAMPP database, all were on before): Application received (`submitted`),
  Renewal received (`renewal_submitted`), Interview date booked (`interview_date_booked`), Appointment reminder (`appointment_reminder`),
  Status update (`status_change`). Switch one back on there to restore it.
- **ID validity:** the one-year clock starts the day staff confirm the ID was handed over (`id_expires_at = today + 1 year`).
- **Expiry reminder:** the daily job `cron/renewal-reminders.php` texts an applicant 30 days before expiry (`renewal_reminder_days` in Admin Settings), once per
  expiry date. Windows task **e-SoloParentID ID expiry reminders** runs it daily at 8:00 AM (Task Scheduler; delete it to stop the reminders).

## 6. Cleanup of unused code and retired features (2026-11-18)
Decided by the owner after the audit in `docs/system-audit-and-plan.md`. Nothing here changes what an applicant, staff member or administrator can do.
Safety copies first: the whole project (`C:\dev\backups\project-before-cleanup-20261118-223837`) and the XAMPP database
(`C:\dev\backups\virac_solo_parent-before-cleanup-20261118-223856.sql`).

| What was removed | Why it was safe |
|---|---|
| `InterviewInvitationService::open/reopen/close/grantReschedule` and their helpers, `InterviewInvitation::linkSms/grantOneReschedule`, `SMSService::notifyInterviewBookingOpen`, SMS template `interview_booking_open` | No page or controller called them. The staff screen keeps its read-only "why no date yet" message; a missed interview still reopens booking automatically. |
| `InterviewDateFeature::forget()` | Never called. |
| Staff form handler `assess_eligibility` / `update_eligibility` and `ApplicationReviewService::assessEligibility()` | No form posted it; eligibility is filled in from the checklist. |
| `ApplicationReviewService::addNote()` and `Application::addNote()` | Nobody could add a note. Existing notes are still read and shown. |
| `AuthController::handleStaffLogin()` | The single login page replaced it. |
| `Appointment::upsertStaffScheduled()` and `linkSmsLog()` | Legacy timed scheduling. Kept only as test fixtures. |
| Database tables `qr_application_slips`, `system_broadcasts`, `chat_messages`, `public_announcements` | No code used them; no foreign keys pointed at them. Dropped from the XAMPP database (it now has 33 tables) and removed from `schema.sql` and `schema_test.sql`. `database/migrate_drop_unused_tables.sql` records the change. |
| Stub pages `admin/login.php`, `staff/login.php`, `staff/chat.php`, `services.php` | Redirect-only. Two old notifications that linked to `staff/chat.php` now link to the staff dashboard. `mswd-login.php` and `index.php` stay. |
| FAQ/announcement leftovers | An empty section in `ContentService`, audit filter names, an unused notification type, two comments. There were no announcement rows or audit entries. |
| Clutter moved to `C:\dev\archive\virac-soloparent-clutter-20261118` | `backups/`, `storage/feature_backups/`, 35 screenshots in `storage/`, four unused images. Moved, not deleted. |

Added: **Reports page (admin)**: links for a full export of all applications and all user accounts (CSV). They use the existing `data.export` permission
and write a "CSV Export" audit entry. The context diagram and Level 1 DFD no longer mention announcements.

## 7. Renewal documents (2026-11-18)
A renewal now asks fewer documents than a new application. `database/migrate_renewal_requirements.sql` (applied to the XAMPP database, 23 rows) sets,
for the 9 active categories (10 to 18), only documents that show the situation is still true today, each uploaded fresh:
new ID photo, barangay affidavit of residency and care, and the sworn sole-care affidavit (categories 11, 15, 17: the sole-care affidavit only);
plus an updated certificate of detention (14) and updated proof the OFW is still abroad (18). One-time proofs (birth, marriage, death certificates,
decrees, CENOMAR, police and medical records) are not repeated. Edit the list in Admin > Requirements. Test: `database/test_renewal_requirements.php`.
Backup before: `C:\dev\backups\virac_solo_parent-before-renewal-requirements-20261118-233234.sql`.
Open for MSWDO to confirm: photo size, whether a valid government ID is added, whether the old ID must be shown.
The old categories 1 to 9 (inactive) have no renewal list; two existing applications belong to them.

## 8. Public status page: less personal information (2026-11-18)
The public "Check Status" page (reference number only) now shows less. Changed: `services/PublicApplicationTrackingService.php`,
`views/home/status.php`, `controllers/ApplicationController.php`, `includes/functions.php` (`no_index_headers()`), `database/test_public_tracking.php`.
- The applicant's name is no longer shown at all (it used to show the first name in full).
- Dates only, no time of day, for the timeline, last update, release date and appointments ("Log in to see the exact time").
- A rejected application shows "A decision was made. Log in to read the decision" instead of "Rejected" / "No ID issued".
- The page is sent with `X-Robots-Tag: noindex` and `Cache-Control: no-store`.
Not done (not approved): a second check (last 4 mobile digits) and a lockout per reference. Reference numbers are still sequential, so someone can still
read the limited status of other references. Built and tested in a separate copy first, then copied into the project.

## 9. Failed texts for staff (2026-11-19)
- **New page `staff/sms.php` ("Failed Texts")** with a count badge in the staff menu: failed texts to applicants from the last 7 days that were not replaced by a newer
  text of the same kind (reset and sign-up codes excluded). Each has Retry SMS when it can be retried; others say why not. An entry leaves the list when it is
  resent, replaced, or after 7 days, so it cannot get stuck. Files: `models/SmsLog.php` (`needingAttention()`), `controllers/StaffController.php`
  (`smsAttention()`, shared `retrySms()`), `views/staff/sms/index.php`, `staff/sms.php`, `includes/sms-attention.php`, `includes/header.php`.
- **Cancel / missed interview** (Appointment Management page) now shows the same Sent / Failed / Not sent box with Retry (`services/AppointmentService.php`
  returns the SMS result). Before, a failure there was silent.
- The application's SMS card no longer says "Ask an administrator"; it says why a text cannot be retried.
- Test: `database/test_staff_sms_attention.php`. Not changed: the daily ID-expiry reminder still raises no alert at 8:00 AM; a failure shows on the new page.

## 10. Documentation cleanup (2026-11-19)
Six Markdown files that were out of date or not part of the system were moved (not deleted) to `C:\dev\archive\virac-soloparent-docs-20261119`:
`appointment-management.md` (described the old staff-types-the-time process), `system-actors-and-functions.md` (older audit, replaced by
`system-audit-and-plan.md`), `database-pruning-report.md` and `runtime-test-plan.md` (never-run plans, now out of date), `codex-cloud.md` (not part of the
system) and `database-import-control-specification.md` (420 KB, referenced by nothing). The few references to them in `README.md`, `schema.sql`,
`schema_test.sql`, `migrate_drop_mobile_code_columns.php` and `mariadb-test-instance-runbook.md` were reworded. A stray render file `docs/diagrams$n.png` was deleted.
Kept: `README.md`, `OFFLINE-PRESENTATION.md`, `public/lib/bootstrap/README.md`, `docs/sms-wording.md`, `docs/database-tooling-safety.md`,
`docs/mariadb-test-instance-runbook.md` (both are referenced by the test tooling), and the documents written for the capstone.

## 11. Defense documents aligned with ISO/IEC 25010:2023 (2026-11-19)
Documents only; no system file was changed. `questionnaires/evaluation-questionnaire.html` Part 3 now rates the five characteristics named in the study's
objectives, using the 2023 names: functional suitability (A, B), performance efficiency (C), reliability (D), security (E) and interaction capability (F, called
usability in the 2011 edition). 21 items, compatibility dropped. `title-defense.md` gained a section mapping the three objectives to the system (sections
renumbered) and `automated-verification-evaluation.md` names the 2023 edition. Copies of the earlier versions: `C:\dev\backups\docs-before-iso2023`.

## 12. Database folder cleanup (2026-11-20)
34 old `migrate_*.sql` scripts (the step-by-step upgrade history, already contained in `schema.sql`, including the four for retired features) and the two
redirect stubs `send_appointment_reminders.php` / `send_renewal_reminders.php` were moved to `C:\dev\archive\virac-soloparent-database-20261120` (36 files, moved not deleted).
Kept in `database/`: the five recent ones (`migrate_auto_verification`, `migrate_renewal_requirements`, `migrate_drop_unused_tables`, `migrate_interview_date_booking`,
`migrate_drop_mobile_code_columns.php`), `migrate_fixed_id_validity.sql` (a test loads it) and three FAQ-text scripts (`migrate_faq_document_uploads`,
`migrate_refresh_help_faq`, `migrate_retire_qr_faq`) until it is confirmed they were applied to the live database. The README sentence about the old migrations
and one comment in `models/AuditLog.php` were updated. Inspection result: all 33 tables are used; only `application_notes.author_id/author_role` and
`appointment_slots` (retired, history only) are unused and were left in place.

## 13. Help (FAQ) wording corrected in the live database (2026-11-20)
Three FAQ rows still carried wording for removed features and were updated (one row each, matched by exact old text, nothing deleted): #12 is now "How do I upload my
supporting documents?" (was about booking a slot), #13 "Where can I find my application reference?" (was the QR application slip) and #15 "Does the Eligibility Checker
approve my application?" (was the online assistant). Backup before: `C:\dev\backups\virac_solo_parent-before-faq-update-20261120-223514.sql`. The three scripts
(`migrate_faq_document_uploads`, `migrate_refresh_help_faq`, `migrate_retire_qr_faq`) were then moved to `C:\dev\archive\virac-soloparent-database-20261120`.
Also checked: the retired `appointment_slots` table has 2 rows that 2 appointments still reference, so it stays.

## 14. "Applicant" is now "Solo Parent" everywhere people read it (2026-11-20)
Pages, messages, settings descriptions, the Filipino dictionary (`public/js/translations.js`, both the English keys and the Filipino text), the seed file and
the stored role name, category descriptions, one document label and one Help answer (`database/migrate_solo_parent_wording.sql`, applied; backup
`C:\dev\backups\virac_solo_parent-before-wording-20261120-224856.sql`). 199+ wording changes in about 49 files, made by a rewrite tool that only edits text people
read and was tested in a separate copy first.
Not changed on purpose (internal names that would break the system): the role key `applicant` (33 accounts), the `applicant/` folder and URLs, `ApplicantController`
and other class names, the `applicants` table, permission names, SQL, code comments, and history records (audit log, status history, old notifications).
Documents in `docs/` were not changed. Filipino sentences now say "Solo Parent" where they said "aplikante": have a Filipino speaker review the grammar.

## 15. README brought up to date (2026-11-20)
`README.md` was rewritten section by section to match the running system (it still described an earlier state: date booking "not active", staff booking controls,
email and mobile login, sign-up and status texts as sent, dropped tables, Bootstrap from a CDN, "nothing verified"). New or rewritten: status header, automated
verification, interview day booking (section 5.1, replacing a 140-line section), public status page rules, SMS (what is automatic, failures, settings), the 33-table
structure, the real test list, ISO/IEC 25010:2023 (five rated characteristics) and the retired features. The old README is kept at
`C:\dev\archive\virac-soloparent-docs-20261120\README.before-update-20261120.md`. Every file named in it was checked to exist.

## 16. Home screen: each required document opens its "how to get it" guide (2026-11-20)
On the public home screen (`includes/portal/overview.php`), every document listed under Required Documents now expands to the same guide the signed-in Solo Parent
sees: where to get it, what to prepare, how to request it, notes on fees, and official links, plus "Contact MSWDO for help". 33 of the 34 document types have a
specific guide; the 2x2 photo has none (as in the signed-in guide). Styles in `public/css/style.css` (light, dark and phone), Filipino strings in
`public/js/translations.js`, test `database/test_home_requirement_guides.php`. Built and tested in a separate copy first.

## 17. Dark mode: text and logo no longer "disappear" (2026-11-21)
Cause: component stylesheets hard-coded light-theme colours (a white panel holding light text, dark text on a dark card), and two portal stylesheets re-declared theme
tokens with light values, which beat the dark theme. Found by measuring every piece of text on 28 screens in a real browser (contrast against the real background behind it):
16 spots failed, e.g. the staff document-review panel (file name, "Verification decision/history"), Reports and Audit-log filter pills, the Solo Parent dashboard stage labels and
the "Needs your action" heading, the faint labels on the staff review rows, the amber status pill (4.37:1) and the disabled buttons.
Fixed in `public/css/preferences-surfaces.css` (dark block), `public/css/style.css`, 11 views/`includes/functions.php` (amber text colour #7a5f12; filter pills now use the class
`filter-pill` instead of inline colours; pager arrow 60% opaque). Hovering every button, link and row on all pages found no further problems (550 elements).
New test `scripts/test-dark-mode-contrast.cjs` (+ `scripts/lib/dark-contrast-probe.cjs`, `scripts/fixtures/applicant-section.php`): fails on the old site (54 problems), passes now.
Logos were checked too: the footer seals and header logo sit on a white backing in dark mode and are visible; no logo was found to vanish. Light mode was not changed except the
pill markup and the darker amber text.

## 18. Dark mode: icons that vanished (2026-11-21)
The home benefit-card icons and the active side-menu icon (every logged-in page) were a light-blue icon on a translucent WHITE tile (1.1:1 contrast), so they looked transparent.
The dark-mode block in `public/css/preferences-surfaces.css` now gives those tiles a dark background (`#223953`), and gives all six benefit cards the same border (two had kept a light
yellow/orange outline). The contrast probe (`scripts/lib/dark-contrast-probe.cjs`) now also measures inline SVG icons (3:1), and `scripts/test-dark-mode-contrast.cjs` fails on them.
Checked: 34 pages in dark and in light mode, 0 low-contrast icons; live public pages 0.

## 19. "MSWDO staff" and "MSWDO admin" (2026-11-21)
Wherever the system says "staff" or "administrator/admin" in text people read (pages, messages, settings descriptions, the Filipino dictionary, role names, the SMS template label, seed files),
it now says "MSWDO staff" / "MSWDO admin" (titles and labels: "MSWDO Staff", "MSWDO Admin"; "an MSWDO admin"). Filipino: "kawani ng MSWDO", "MSWDO admin". About 190 wording changes in about 50 files,
made by a rewrite tool that edits only text people read; every changed English sentence was checked against its Filipino dictionary entry (0 mismatches).
Database (`database/migrate_mswdo_role_wording.sql`, applied; backup `C:\dev\backups\virac_solo_parent-before-mswdo-roles-20261121-005228.sql`): role names are now "MSWDO Admin" and "MSWDO Staff",
and one SMS template label. Not changed on purpose: the role keys `admin` / `mswd_social_worker` (3 accounts), role codes, folders, class names, permission names, history records (audit log, status history,
notifications), and staff of OTHER offices in the requirement guides ("barangay secretary or designated staff"). Also corrected a schema message in `services/InterviewDateFeature.php`
(it listed the enum value as 'SOLO PARENT'; the real value is APPLICANT). Layout checked on 28 screens at desktop and phone width: no overflow introduced.

## 20. README brought up to date again (2026-11-21)
`README.md`: status header (13 database suites, the dark-mode test, date); a wording note (Solo Parent, MSWDO staff, MSWDO admin; internal keys unchanged); feature table rows for the home-screen
"How to get it" guides and the light/dark display; sections 8 and 9 renamed "MSWDO Staff Workflow" / "MSWDO Admin Responsibilities" and role words in the prose; the test table (new
`test_home_requirement_guides.php`, the dark-mode test now covering icons); the result line; open items 8 and 9. Previous copy: `C:\dev\backups\README.before-update-20261121.md`.

## 21. Interview time set by MSWDO staff (2026-11-21)
The staff "Schedule the interview date" card now has an Interview time field (required on the form, default 9:00 AM, 7:00 AM up to the office closing time). `InterviewBookingService::scheduleForApplicant` takes an optional `$time` (HH:MM, validated), `Appointment::bookDateOnly` stores it in `scheduled_at`, the date-only flags and capacity count are unchanged. The SMS (`interview_date_assigned`) shows the time in place of the office-hours range; `interview_schedule_text` and the Solo Parent dashboard show a time when one is stored. No database change. Checked: PHP lint of every changed file, a direct check of the display helper, and the offline SMS notice suite. The database suites were NOT re-run (the test instance on 127.0.0.1:3307 was not running).

## 22. Documents sent for verification: first come, first served, with alerts (2026-11-21)
The MSWDO staff Document Verification Queue is now ordered by the time each Solo Parent sent the document (oldest waiting document first; a re-upload counts from the moment it was re-sent), shows a place number, the send time and a **Re-uploaded** marker, and lists 10 rows. New dashboard tile **Re-uploaded Documents**. New menu entry **Documents to Verify** with a count (orange when any are re-uploads). A toast appears on staff pages when a newer document arrives (`public/js/staff-doc-alerts.js` asks `staff/doc_alerts.php` every 30 seconds; read-only). The staff notification now reads "RE-UPLOADED: ... needs your verification". The re-upload is detected from the existing document trail note (`Document::RESUBMIT_NOTE`); no database change. New test `database/test_document_verification_queue.php`. Checked on the isolated test instance: the new suite and 12 others pass, the guard checks and 3 offline suites pass. The dark-mode test was not re-run.

## 23. Staff dashboard shows the interview time (2026-11-21)
`interview_time_text()` (used by Today's Appointments on the staff dashboard and the staff appointment list) now shows the time when MSWDO staff set one for a date booking; a date with no time still reads "Any time today". Upcoming Interviews already showed it through `interview_schedule_text()`. Checked: lint and a direct check of the helper (09:00 shows "9:00 AM", midnight shows the label, timed rows unchanged).

## 24. First come, first served removed from the staff verification queue (2026-11-21)
On request, only the ordering from section 22 was undone: the Document Verification Queue is again ordered oldest application first (by submitted date), without place numbers, send times or the "first come, first served" hint. Kept from section 22: the **Re-uploaded** marker, the Re-uploaded Documents tile, the Documents to Verify menu entry with its count, the alert toast, the new notification wording and the 10-row list. `test_document_verification_queue.php` now checks oldest application first.

## 25. Next-step popup after Save Verification (2026-11-21)
On the staff application page, Save Verification now opens the next step as a popup (like the document viewer) when something changed: a document marked **Needs Correction** opens the correction SMS review (Confirm and Send); when every document is **Verified** and no interview is set, the **Schedule the interview** form opens (date, time, SMS choice). The popup moves the page's own panel (the same form and server checks) into the dialog and puts it back when closed, so nothing is duplicated; **Later** closes it. A **Next step** banner on the document card reopens it at any time, and a scheduling error reopens the schedule popup so the message is seen with the form. Correction comes first when both apply; no popup when nothing changed or nothing is waiting. New: `public/js/staff-next-step.js`, `scripts/test-next-step-popup.cjs`, dark-theme rules for the popup in `preferences-surfaces.css`. `StaffController` adds `&next=1` to the redirect after a save that changed documents. No database change. Not done: an "Approve now" prompt for renewals that need no interview. Checked: lint, the new browser test, the 28-screen dark-mode test, and the staff page renders without errors.

## 26. The duplicate Schedule and Correction SMS panels removed (2026-11-21)
On the staff application page the long inline **Schedule the interview date** form and the inline **Correction SMS** review are no longer shown on the page; both forms live in hidden holders (`#schedule-store`, `#correction-review-store`) and appear only in the next-step popup. Kept inline: the interview status pill, the reschedules-used / closed summary, the correction heading, the sent / failed notice and the latest-SMS line with its retry. New permanent buttons **Schedule the interview** and **Review and send correction SMS** (`data-next-step-open`) open the popup at any time. A link to `#schedule-interview` (the dashboard and staff appointment lists) opens the schedule popup. `staff-next-step.js` and `scripts/test-next-step-popup.cjs` were updated (buttons, hidden forms, link). No server rule changed. Checked: lint, the popup test, the 28-screen dark-mode test, all 28 pages render, 4 database suites pass. `test_staff_review_workflow` still fails at the same assertion as the committed version (the known test-instance gap), so it is not caused by this change. Without JavaScript the forms cannot be reached (accepted).

## 27. TESTING ONLY quick-schedule switch (2026-11-21)
New setting **testing_quick_schedule** (admin Settings, Appointment settings; **off by default**). When on: MSWDO staff may schedule an interview for today or a later day at any time (the office-day, holiday, 45-day and office-hours checks are skipped; capacity still applies), the time field defaults to five minutes from now, the schedule popup shows a TESTING MODE notice, and the interview result can be recorded right away (so approval and ID release can follow without waiting). ID release already accepts any future time, so it needed no change. When off, every rule is exactly as before. Code: `testing_quick_schedule()` in `includes/functions.php`; `InterviewBookingService::scheduleForApplicant`, `ApplicationReviewService` (record result), `Appointment::canRecordResult`, the staff page and `StaffController`. New test `database/test_testing_quick_schedule.php`. **Remember to switch it off before real use.** Checked: lint, the new suite and 3 others, guard checks, all 28 pages render.

## 28. TESTING ONLY quick-schedule switch removed (2026-11-21)
On request, section 27 was undone completely: the setting, the `testing_quick_schedule()` helper, the relaxed scheduling and result-recording rules, the TESTING MODE notice and its test were removed (the test file is kept in `C:\dev\archive\quick-schedule`). Interview scheduling is back to the normal rules: an office day after today, within 45 days, with the time inside office hours (7:00 AM to closing). The interview **time** field from section 21 stays. Checked: lint, no remaining references to the switch.

## 29. Staff can schedule any date and time, including now (2026-11-21)
On request, MSWDO staff scheduling is no longer limited to office days and hours: any date from today (up to 365 days ahead, `InterviewBookingService::STAFF_WINDOW_DAYS`) and any time of day are accepted, including right now; past dates and a malformed time are refused; the daily capacity still applies. The form starts on today and the current time, with no min/max on the time. ID release also accepts the current minute (before: strictly in the future). The Solo Parent's own date booking is unchanged (office days, up to 45 days). The interview result can be recorded once the scheduled time has arrived, so an interview set for "now" can be recorded at once. New end-to-end test `database/test_staff_any_time_schedule.php` (the throwaway database gets the three booking tables and columns from the migration, and the readiness guard is told it is ready for that test only). This replaces the testing switch removed in section 28.

## 30. Fix: Schedule Date and Send SMS showed "Something went wrong" (2026-11-21)
Cause (from storage/logs/php-error.log): the database refuses a date-only appointment that has a time of day (chk_appt_date_only: date_only = 0 OR time = 00:00:00), so saving a staff booking WITH a time failed. Fix: a staff booking with a time is stored as a timed appointment (date_only = 0, booking_source STAFF) and still counts toward the day's capacity; a staff booking without a time is unchanged. The Solo Parent can still keep it or choose another day (isStaffAssigned now keys on booking_source = STAFF). No database change. The test now recreates the CHECK constraint in its throwaway database, so it would have caught this.

## 31. Interviews nobody confirmed (2026-11-21)
Staff: new dashboard tile **Interviews to Confirm**, a **Confirm who attended** list (Attended / Did not attend), and a badge on Appointment Management; they use the existing awaiting-outcome query. System: `AppointmentService::releaseUnconfirmed()` releases a BOOKED interview still without an outcome 2 weekdays (`RELEASE_GRACE_WEEKDAYS`) after its date: the appointment becomes MISSED with the note "Released automatically: no outcome was recorded", the invitation reopens, the application returns to its earlier status, events NO_OUTCOME and INVITATION_REOPENED (source SYSTEM) are written, the Solo Parent gets an in-app notice and staff an in-app alert. It is NOT a counted miss (the 5-miss limit counts MISSED events only) and **no SMS** is sent. It runs from the staff dashboard (at most every 10 minutes) and from the optional job `cron/interview-followup.php`. Solo Parent: the dashboard banner says "Please choose a new interview date" when the last interview was missed or released and booking is open. New test `database/test_unconfirmed_interview_release.php`. No database change. Not built: recording "attended" after the system has released an interview (staff can schedule a new date instead). Timed interviews appear under "to confirm" once their time has passed; the release counts weekdays from their date.

## 32. "Did not attend" never sends an SMS (2026-11-21)
On request, marking an interview as missed (Confirm who attended, or the appointment list) sends no SMS in any case; before, an SMS was sent only when the Solo Parent could no longer rebook (after the 5th miss). The Solo Parent sees the in-app notice, the dashboard banner "Please choose a new interview date" and the Appointment page when they log in, and picks a new day with the existing booking screen. The staff message now says so. The test also checks that a staff-confirmed miss reopens the booking, is counted, and sends no SMS. Cancelling an appointment still texts the Solo Parent.

## 33. Did not attend stays on the staff dashboard (2026-11-21)
The Did not attend button in the dashboard's Confirm who attended list posted to the Appointment Management page and redirected there. It now sends return_to=dashboard, so on success (and on an error, shown as a message) the staff member lands back on the dashboard at the same list; the appointment list's own buttons still return to the appointment list. The dashboard shows the confirmation message at the top.

## 34. Fix: Did not attend showed "Something went wrong" (2026-11-21)
Cause (storage/logs/php-error.log): some records in the live database belong to a Solo Parent account that no longer exists (11 applications), and the in-app notice to that account failed on its foreign key AFTER the miss was saved. Fix: `AppointmentService::notifyUser()` skips an account that does not exist and logs, instead of throwing, for the missed/cancelled notice and for the reminder jobs (a missing account could also have stopped the reminder run). The test now deletes the account and confirms Did not attend still works with foreign keys enforced. The 11 orphaned records were not changed.

## 35. Failed SMS: the real cause in plain words (2026-11-21)
`SMSService::failureHelp()` turns the stored failure text into a cause and what to do: no internet, slow connection, invalid token or no credit, SMS not set up, invalid number, allowlist, daily limit, duplicate, switched off, gateway refusal, or unknown. The staff notice after scheduling, the application's SMS list and the Failed Texts page now show **Cause**, **What to do** and the original gateway message under **Details** (so "Invalid api token or no load balance" is still shown). Offline test: 25 checks (10 new). No database change.

## 36. Staff can see that a Solo Parent rescheduled (2026-11-21)
When a Solo Parent books a new interview date after a missed interview, one the system released for lack of an outcome, a cancelled one, or instead of the date staff set, it is recorded as a reschedule: the **status history** reads "Rescheduled by the Solo Parent after a missed interview (previous date Nov 19, 2026): new interview date ... booked", the interview event trail says the same, and the staff in-app alert starts with "RESCHEDULED:". On the staff application page the Interview card shows an orange **Rescheduled by the Solo Parent** note (new date, previous date and why) and a collapsible **Interview history** list of every event (scheduled, missed, released, reopened, rebooked). New AppointmentEvent::forApplication(). No database change. Test: 	est_unconfirmed_interview_release now books again after a miss and checks the history and events.
