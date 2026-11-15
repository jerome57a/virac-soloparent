# Database pruning report

Status: **Phases 1 and 2 prepared in files; nothing executed.** No SQL, migration, runtime test or database connection was
run. Phase 3 and the checklist-table review are investigation only. All historical tables are kept.

## 1. Phase 1 (applied to files only)

| Item | Change |
|---|---|
| Outdated comments | `schema.sql` / `schema_test.sql`: appointments section, `appointment_slots`, `appointments.status`, `sms_templates`, `chat_messages`, `faqs`, `mobile_verified_at`, and a note on `application_document_checklists` |
| `mobile_verification` template | Removed from `seed.sql` and `testing/seed_test.sql`. `SMSService::TEMPLATES` already had no such key. Existing `sms_templates` / `sms_logs` rows are not touched |
| FAQ #8 | `seed.sql` now describes document uploads. Existing databases need `migrate_faq_document_uploads.sql` (not run); it matches the exact old question and answer, so reworded rows are skipped |
| README | `faqs` is **live** (read by `get_faqs()` for the Help page and portal search), not retired data. `eligibility_result_views` is **live** (written by the public checker, read by eligibility history). Slot/booking wording, SMS-code wording and the docs link corrected |
| `seed_test.sql` | Dropped the unused `appointment_default_capacity` setting (no code reads it) |

SMS template **bodies and labels in `SMSService::TEMPLATES` were not changed**: they are message text sent to applicants.
See unresolved item 3.

## 2. Phase 2: `users.mobile_code*` removal

PHP references removed (these were the only three):
`models/User.php` (`updateAccount`, `markMobileVerified`), `models/Applicant.php` (`updateContact`).
`mobile_verified_at`, `password_reset_code` and `password_reset_expires_at` are unchanged and still used.
Schema files (`schema.sql`, `testing/schema_test.sql`) no longer define the three columns. No test fixture inserted them.

Prepared, **not executed**:

* `database/migrate_drop_mobile_code_columns.php`. Reuses `tool_safety.php`, so it only runs against the isolated
  `virac_solo_parent_test` copy with a non-root account and `SMS_PROVIDER=test`. `--check` (default) reads
  `information_schema` and one aggregate `COUNT(*)`. It verifies the three columns match their expected definitions, that the preserved
  columns exist, and that no index, foreign key, trigger, view or check constraint uses them. `--apply` also needs
  `--backup-confirmed`, refuses if any account still holds a value in those columns unless `--discard-pending-codes` is given, runs one
  `ALTER TABLE users DROP COLUMN ...`, then verifies the columns are gone, the preserved columns have identical definitions and the row count is unchanged.
* `database/rollback_restore_mobile_code_columns.sql`: structure-only rollback. Values return only from the dump.

Rehearsal plan on a copy (after approval): restore a dump into `virac_solo_parent_test`, take a second dump, run `--check`, run
`--apply --backup-confirmed`, rerun `--check` (must report the columns missing), run the existing suites against the copy, and
exercise the rollback by restoring the dump. A production run needs a separate approval and a guarded production mode that does not exist yet.

## 3. Phase 3: `appointments.slot_id` (investigation, not removed)

| Dependency | Where |
|---|---|
| Foreign key `fk_interview_slot` to `appointment_slots(id)` ON DELETE SET NULL, and index `idx_appt_slot_status (slot_id, status)` | `schema.sql`, `migrate_applicant_portal.sql`, `migrate_step11_appointments.sql` |
| Written: always set to NULL on staff scheduling/rescheduling | `Appointment::upsertStaffScheduled` (`ON DUPLICATE KEY UPDATE slot_id = NULL`) |
| Read: `closeAppointment()` compares `slot_id` as a concurrency check | `services/AppointmentService.php:170` |
| Read: `SELECT *` style reads expose it to callers | `models/Appointment.php` |
| `appointment_slots` itself is still referenced | `models/User.php:134` (staff "has records" guard), `models/AuditLog.php:136` (entity label), FK `fk_slot_creator` to `users(id)` with no ON DELETE action, so it blocks deleting a staff account that created slots |
| Tests that build legacy slot rows | `appointment_management_checks.php`, `automatic_interview_checks.php`, `checklist_flow_checks.php`, `test_user_account_actions.php` (temporary table) |
| Docs | `docs/appointment-management.md`, `docs/runtime-test-plan.md` (cases 2.1, 2.5, 2.7, 2.16) |

Findings: new rows never carry a slot; older rows may. Dropping the column needs the FK and index dropped first and would erase which
slot an older appointment used, with no way back except a dump. Whether any live row still has a non-NULL `slot_id` is **not known**: that
needs a database query, which was forbidden. **Recommendation: keep `slot_id`, its FK/index and `appointment_slots`.** Revisit only after an
approved `SELECT COUNT(*) ... WHERE slot_id IS NOT NULL` on a copy.

## 4. `application_document_checklists` (investigation, not removed)

**Verdict: it is not only the retired checkbox workflow, and it must stay.** Uploaded-file verification lives in `application_documents`
(plus `document_status_history`); the checklist table now holds legacy declarations plus renewal evidence, and the code still reads and writes it.

Active **production** references:

| Use | Reference |
|---|---|
| Read: the merged requirement list for every applicant, staff and admin screen (uploads are overlaid on checklist rows) | `Document::checklistForReview`, called from `StaffController`, `AdminController`, `DocumentController`, `ApplicantController`, `AppointmentController`, `ApplicationTrackerService`, `ApplicationReviewService`, `SMSService:282`, `RenewalRequirement::plan` |
| Read: staff verification queue counts rows with `applicant_has = 1` that have no upload | `Document::PENDING_DOCUMENTS_SQL` |
| **Write (insert):** a simplified renewal snapshots its requirements, carrying forward accepted evidence | `RenewalRequirement::snapshot` (the only production INSERT) |
| **Write (update):** staff "undo verification" resets rows to Pending and locks them | `ApplicationReviewService::undoReview` (called from `StaffController:165`) and `reviewUndoToken` |
| View logic keyed on its columns | `views/applicant/documents/index.php` (`source_checklist_id`), `views/partials/document-review.php`, `requirements-preparation.php` |

**Production-unreachable** (only referenced by tests): `verifyAvailableChecklistOnOpen`, `receiveChecklistDocument`, `reviewChecklistItem(s)`,
`verifyAllChecklistItems`, `confirmAllRequiredDocuments`, `Document::updateChecklistReview`. They are the retired checkbox-verification write
paths and are covered by `test_staff_review_workflow.php`, `checklist_flow_checks.php`, `test_simplified_renewal.php`. These are candidates for a later *code* cleanup, not a table change.

Other references: schema (`schema.sql`, `schema_test.sql`), `migrate_document_checklists.sql` (creation and backfill),
`migrate_simplified_renewal.sql` (renewal columns), `seed_test.sql`, and the tests above. `source_checklist_id` is informational (no FK).

Open risk: no production code inserts a checklist row for a *new* (non-renewal) application. Paths that need `$row['id']`
(`confirmAllRequiredDocuments`, `verifyAllChecklistItems`, `reviewChecklistItems`) would report "checklist record is missing" for such rows, but they are unreachable today, so no live effect. Confirming this and whether historical rows exist needs data access, which was not allowed.

## 5. Preserved tables

`chat_messages`, `qr_application_slips`, `public_announcements`, `system_broadcasts`, `appointment_slots`, `faqs`,
`eligibility_result_views` are all kept; nothing was dropped or truncated. `sync_schema.php` is check-only and does not flag extra columns, so a copy that still has the old columns will not be reported as drifted.

## 6. Unresolved issues

1. **Existing databases still have the three columns and the old FAQ #8 text** until the (unapproved) migrations are rehearsed and run.
2. The `rate_limit_hits` table may hold keys prefixed `mobile_code:` from the retired flow. Those are rows, not columns, and were left alone.
3. `SMSService::TEMPLATES['document_correction']` still reads "update your Required Documents Checklist", and `notifyDocumentCorrection()` sends fixed placeholder text instead. The wording is outdated; the label and body were not changed because they are applicant-facing SMS text.
4. `docs/runtime-test-plan.md`, `docs/database-tooling-safety.md` (mentions slot-capacity checks) and README section 19's roadmap are partly stale. Only the README items you listed were corrected.
5. `schema_test.sql`'s header says it derives from `schema.sql` "lines 35-630"; the line numbers have shifted slightly. It should be re-derived before its next import.
6. No production-run mode exists for the column migration (see section 2).
7. While searching for the column name, a recursive grep also scanned `storage/db_backups` and `storage/feature_backups`. Only matching lines (column definitions, INSERT column lists and one `rate_limit_hits` key) were printed; no applicant values were shown or used.

## 7. Modified and new files

Modified: `models/User.php`, `models/Applicant.php`, `database/schema.sql`, `database/testing/schema_test.sql`, `database/seed.sql`,
`database/testing/seed_test.sql`, `README.md`.
New: `database/migrate_drop_mobile_code_columns.php`, `database/rollback_restore_mobile_code_columns.sql`,
`database/migrate_faq_document_uploads.sql`, `docs/database-pruning-report.md`.
Only `php -l` syntax checks were run, on the three PHP files.
