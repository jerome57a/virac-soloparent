# System actors, their functions, and what is not used

Prepared by inspecting the code, the permission map (`config/permissions.php`), every entry-point page, every
controller action and form, the cron jobs, the database tables, and a full reference scan of every class method
and function (725 declarations in 209 application files). Findings marked "verified" were checked by hand;
the scan alone can mislead (for example, methods called through array callables or through a chained object).

Test status when this was written: the full end-to-end run of the workflow passes (45/45 checks), run on a
throwaway database. The real application was not changed by this inspection.

---

## 1. Actors

| Actor | Who | How they sign in | Permissions (`config/permissions.php`) |
|---|---|---|---|
| Public visitor | Anyone, not signed in | n/a | none |
| Applicant | A solo parent with an account | `login.php` (unified sign-in) | `applicant.portal` |
| MSWD staff | Role `mswd_social_worker` | `login.php` | `staff.dashboard`, `applications.process`, `applicants.view`, `appointments.view`, `reports.view`, `reports.export` |
| Administrator | Role `admin` | `login.php` | `admin.dashboard`, `applications.monitor`, `applicants.view`, `appointments.view`, `reports.view`, `reports.export`, `users.manage`, `settings.manage`, `content.manage`, `sms.manage`, `audit.view`, `eligibility.logs`, `data.export` |
| System (automatic) | Scheduled jobs and automatic side effects | n/a | n/a |

Rules built into the permission map: only staff decide applications; administrators monitor applications
read-only; administrators alone manage users, settings, SMS, audit and exports; no staff or admin account can act
as an applicant.

---

## 2. Public visitor

Pages: `portal.php` (tabs: overview, login, apply, my applications, status, help, search), `eligibility.php`,
`register.php`, `login.php`, `forgot-password.php`, `reset-password.php`.

| Function | Where |
|---|---|
| See the portal overview, services and help/FAQ | `portal.php` |
| Run the preliminary eligibility checker (guidance only; the result is saved and can be claimed after sign-in) | `eligibility.php` |
| Track an application by reference number (limited, public view) | `portal.php?tab=status` |
| Register an applicant account | `register.php` |
| Sign in (applicants, staff and admins all use the same page) | `login.php` |
| Request a password reset code by SMS and set a new password | `forgot-password.php`, `reset-password.php` |

Protected: `download.php` (documents) and `avatar.php` (profile photos) refuse anyone who is not the owner or staff.

---

## 3. Applicant

Entry: `applicant/dashboard.php?section=...` (all sections below). Sections: dashboard, apply, application,
renewal, myid, appointment, notifications, documents, requirements, profile, help.

| Section | What the applicant can do |
|---|---|
| Dashboard | See their status summary, next action and recent notifications |
| Apply | Multi-step application: personal details, children, documents, review and submit. Save a draft, go back, jump to a step, add or remove a child, upload files, discard the draft |
| My Application | See the progress tracker, status history, interview date and ID release schedule |
| Supporting Documents | See each required document and its verification status, upload or replace a file |
| Appointment | After staff send an invitation: choose an interview day (one month shown at a time; weekends, holidays and closures are never offered), see the booked date, cancel it (within the deadline) and rebook (limited reschedules) |
| Renewal | Start a renewal request, save or discard its draft, see renewal status and history |
| My Solo Parent ID | View the ID record once released |
| Requirements Guide | Read what each document is and how to get it |
| Notifications | Read, mark one read, mark all read |
| My Profile | Edit personal info, set a username, change password, upload or remove a profile photo |
| Help & Support | FAQ and contacts |

Also: log out; run the public eligibility checker while signed in.

---

## 4. MSWD staff

Pages: `staff/dashboard.php`, `staff/index.php` (Applications), `staff/application.php?id=` (review),
`staff/applicants.php` and `staff/applicant_view.php` (Applicant Records), `staff/appointments.php`,
`staff/reports.php`, `staff/notifications.php`, `staff/account.php`.

| Area | Functions |
|---|---|
| Dashboard | Counts (pending review, under verification, documents to verify, pending corrections, renewals), Expected Today, today's and upcoming interviews, document queue, recently processed |
| Applications list | Search by reference or name; filter by barangay, status, type; "Next step" line on every row |
| Review page | Opening starts the review automatically; verify, correct or reject each uploaded document (Save Verification); preview-and-confirm one correction SMS per batch; undo a review section; send, close or reopen the interview booking invitation (automatic or custom SMS); grant one extra reschedule; record the interview result (Successful or Unsuccessful); approve or request corrections (remarks required for corrections); schedule the ID release with an SMS and retry a failed one; confirm the ID was handed over; retry a failed interview SMS; view documents and profile photo |
| Appointment Management | Lists: needs an invitation, invited and waiting for a date, needs an outcome, today, upcoming; read-only "Interview days" seat summary; cancel and notify the applicant; mark a no-show; record a result |
| Applicant Records | Search and open a full record: profile, applications, renewals, documents, appointments, invitations, decisions and history |
| Reports | Seven reports (applicant, application, approval, rejection, renewal, appointment, expiring ID) with filters, print and CSV download |
| Notifications and Account | Read and mark notifications; change own password |

Removed from the staff screens during this work: the Reject button, the "Remarks for the applicant" box, the Case Notes
form, the Preview Correction SMS button, and the manual "open interview dates" form.

---

## 5. Administrator

Pages: `admin/dashboard.php`, `admin/applications.php` and `application_view.php`, `admin/applicants.php` and
`applicant_view.php`, `admin/appointments.php`, `admin/reports.php`, `admin/eligibility_logs.php`,
`admin/users.php` and `user_edit.php`, `admin/settings.php`, `admin/requirements.php`, `admin/sms.php`,
`admin/audit_log.php`, `admin/notifications.php`, `admin/account.php`, `admin/export_csv.php`.

| Area | Functions |
|---|---|
| Dashboard | Totals by status, expiring IDs, needs-attention counts (failed SMS, locked accounts), recent activity |
| Application Monitoring | Read-only list and view with the same "Next step" line; no approve, reject or verify |
| Applicant Records, Appointment Overview, Reports | Same read access as staff, without any actions that change an application |
| Eligibility Logs | Review preliminary eligibility checks (guidance only) |
| User Management | Create accounts for any role; edit; activate and deactivate; delete (only when no history); change role; reset access; unlock a locked account; confirm a mobile number; view an account's activity |
| System Settings | Sections: Basic System Information, Document Limits, Appointments (working days, holidays, closures, default daily capacity, reminder timing), ID Validity & Renewal, Notification Settings; run the appointment and renewal reminder jobs manually; read-only server information |
| Application Requirements | Add, edit, delete document requirements; configure renewal requirements per category |
| SMS Management | View logs with type and delivery status; retry a failed SMS; send a test SMS; edit SMS templates |
| Audit Logs | Search and filter every recorded action (permanent, cannot be edited) |
| Data export | CSV export of applications |

---

## 6. System (automatic)

| Function | How it runs |
|---|---|
| Appointment reminder SMS and notification | `cron/appointment-reminders.php`, hourly (Task Scheduler); can be run manually from Settings |
| Renewal reminder SMS and notification (and an "expired" catch-up) | `cron/renewal-reminders.php`, daily |
| Submission, status-change and booking-confirmation SMS and in-app notices | Triggered by the actions above |
| Audit logging of every important action | Automatic; the database blocks edits and deletes (triggers) |
| Login lockout and rate limiting | Automatic on repeated failed sign-ins |
| Developer tools (command line only) | `database/seed_admin.php`, `database/sync_schema.php`, migrations, test and check scripts, `scripts/*.cjs` browser tests |

Not automatic any more: interviews are no longer scheduled by the system on submission. The "System" label in
the Booked By column only identifies older records created that way.

Old locations kept so existing schedules keep working: `database/send_appointment_reminders.php`,
`database/send_renewal_reminders.php` (they just call the cron scripts).

---

## 7. Not used (verified)

### 7.1 Code no screen or flow reaches (dead code)

**Removed on 2026-11-17** (originals saved in `backups/dead-code-removal-2026-11-17/`; syntax check of all 233 PHP files,
the 45-step end-to-end run and a render of every staff and admin page all pass afterwards):

| Removed | Why it was dead |
|---|---|
| `InterviewDateService::open`, `reopen`, `close`, `setCapacity` and their private helpers; `InterviewDate::markReopened`, `markClosed`, `setOverride` | The staff screen for opening dates was removed; applicants now choose any office day. No test used them |
| `InterviewDateFeature::dateOnlyColumnReady` | No callers |
| `EmergencyContactService::validate` and `views/partials/emergency-contact-fields.php` | The form fields were never shown and nothing called the validator |
| `views/staff/appointments/schedule-form.php`, `scripts/fixtures/appointment-scheduling.php`, `scripts/test-appointment-scheduling.cjs` | The old staff scheduling form and the browser test built only for it |
| The `add_note` staff form handler (and its error plumbing) | The Case Notes form was removed. Stored notes are still shown read-only |

Kept on purpose: `InterviewDateFeature::forget` (a documented helper for verification scripts, used by my end-to-end
script); `ApplicationReviewService::addNote` and `Application::addNote` (a test uses them; notes are still stored and shown).

**Removed in the second round (same day, same backup folder):**

| Removed | Why it was dead |
|---|---|
| `ApplicationReviewService::scheduleInterview` | Staff no longer pick a date and time; an invitation and the applicant's own booking replaced it |
| `SMSService::notifyInterviewScheduled`, `interviewScheduleMessage` | Used only by `scheduleInterview` |
| `InterviewInvitationService::beforeStaffScheduled`, `afterStaffScheduled`, `otherSeatsUsed` | Hooks called only by `scheduleInterview` |
| `InterviewCalendarService::timeError` (and the office-start setting it alone read) | Interviews are date-only now; only the office closing time is still used (for the cancellation deadline) |
| `ApplicationReviewService::receiveChecklistDocument`, `confirmAllRequiredDocuments`, `verifyAllChecklistItems`, `reviewChecklistItems` | The old physical-receipt checklist, replaced by upload and verification |
| `SMSService::notifyDocumentCorrection` | A blocked stub; the real correction SMS goes through Confirm and Send Correction SMS |

Tests changed with them (they cannot be run without the isolated test server, so they are syntax-checked only):
`appointment_management_checks.php` and `automatic_interview_checks.php` were rewritten to keep every check of live behaviour
(result timing, cancellation, no-shows, reminders, SMS retry, completed interviews, closed days, closure settings) and drop
the checks of staff scheduling itself; `checklist_flow_checks.php` lost the scheduling document-gate matrix
(`check_interview_document_gate`) and uses a shared fixture, `review_book_interview()`, in `test_staff_review_workflow.php`;
`test_simplified_renewal.php` lost three "staff cannot schedule a renewal" assertions. These suites were already out of step
with the application before this work (for example they expected old staff-page wording), so run them once on the test
server and fix whatever they report.

**Kept on purpose**

| Item | Why |
|---|---|
| `AuthController::handleStaffLogin` | `test_login_identifiers_offline.php` uses it for seven staff sign-in security checks. Keep until that test is pointed at the live login path |
| `InterviewDateFeature::forget` | A documented helper for verification scripts |
| `Appointment::upsertStaffScheduled`, `Appointment::linkSmsLog` | Now only used by the test fixtures that create appointments |
| `ApplicationReviewService::addNote`, `Application::addNote` | A test uses them; stored notes are still shown |
| Staff handlers `assess_eligibility`, `update_eligibility` | Part of the eligibility workflow, deliberately left alone |

Resolved: the admin setting **Earliest interview start** (`appointment_day_start`) was read by nothing, because interviews are
date-only. It has been removed from the Settings page, and "Latest interview end" is now called **Office closing time**.

Public methods only used inside their own file (could be private, not harmful): `AppointmentService::changeableByApplicant`,
`formatCancellationDeadline`, `InterviewCalendarService::closingTime`, `previousOfficeDay`, `NotificationService::notify`,
`notifyRoles`, `safeLink`, `SMSService::preparationReminder`, `preparationText`, `uncheckedRequirementLabels`,
`UserManagementService::temporaryPassword`, `RequirementGuideService::forRequirements`, `instructions`.

Note: the four optional emergency-contact fields are not collected any more: `ApplicationService::collectStepInput` blanks them
every time step 1 is saved. Their columns and the ID-card display remain for older records.
### 7.2 Stub pages kept only for old links

`services.php`, `index.php`, `mswd-login.php`, `staff/chat.php`, and the applicant redirects for `section=chat`,
`section=settings` and `section=eligibility`. Two stored staff notifications still link to `staff/chat.php`.

### 7.3 Database tables the application code never touches

| Table | Rows | Note |
|---|---|---|
| `chat_messages` | 17 | Retired chat; kept on purpose |
| `qr_application_slips` | 23 | Retired QR slips |
| `public_announcements` | 5 | Retired announcements |
| `system_broadcasts` | 0 | Retired |
| `appointment_slots` | 2 | Retired slot booking; only the audit-log label lookup and the user-delete check still mention it |

### 7.4 Checked and fully used

All 16 permissions are enforced somewhere; every system setting in `config/settings.php` is read by the app;
all 13 SMS templates are sent by something; every other admin and staff form action has a matching button.

### 7.5 Tests that exercise unreachable paths

The check scripts in `database/` (`appointment_management_checks.php`, `automatic_interview_checks.php`,
`checklist_flow_checks.php`, `test_simplified_renewal.php`) call `scheduleInterview` about forty times. They test a
path no actor can reach any more, and they do not cover the new applicant-chooses-a-date flow.
