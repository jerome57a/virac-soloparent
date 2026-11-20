# Title defense: e-SoloParentID, an Online Application and Renewal System with Automated Verification and SMS Notification

Updated 2026-11-19 to match the system as it is now. Fill in every [bracket] with your own measured result before you present.
Do not present a number you have not measured.

## 1. The claim in one paragraph

e-SoloParentID lets a solo parent in Virac apply for a Solo Parent ID, and later renew it, entirely online: the applicant fills in the form and
uploads the documents, and MSWD staff process the application in the same system. The system automatically checks each application, new or
renewal, against fixed rules and reports the result with plain-language reasons. The checks look at the information entered and at the files
uploaded: required documents present, dates and ages consistent, no file reused or already used by another applicant, each photo large,
bright and sharp enough to read and, where text recognition is installed, a name from the application found on each document. The applicant sees
these results before submitting and can correct problems at once; MSWD staff see them on the application page and in the queue. The system
recommends and the MSWD social worker makes the final decision on every document and application. "Automated" means the checking, not the
deciding, is done by the system. Applicants are told by SMS about the steps that need them (interview date, document corrections, ID release,
cancellations) and are reminded automatically 30 days before their ID expires, so they know when to renew.

## 2. The three objectives and where the system meets them

| Objective (Statement of the Problem) | Where it is met | Evidence |
|---|---|---|
| 1. Design and development of the e-SoloParentID system | The applicant, staff and administrator portals: online application and renewal, document uploads, automated checking, staff review, interviews, ID release, reports and an audit log. Designed with the context diagram, Level 1 DFD and architecture diagram in `docs/diagrams/` | The running system; the diagrams; the test suites |
| 2. Integration of automated SMS notifications | Real texts through the IPROG gateway: interview date, document corrections, ID release, cancellations, reset codes and the automatic ID-expiry reminder 30 days before expiry. Every text is logged; staff see Sent or Failed and can retry | 15 real texts in the SMS log; `test_sms_notice_offline.php`; `test_staff_sms_attention.php`. Status-update and "application received" texts exist but are switched off to save credits; switch them on in Admin > SMS > Notification Templates for the demonstration |
| 3. Software quality evaluation using ISO/IEC 25010:2023 | Questionnaire, Part 3, with five characteristics: functional suitability, performance efficiency, reliability, security and interaction capability (named "usability" in the 2011 edition). A five-point Likert scale with frequency count and weighted mean | `docs/questionnaires/evaluation-questionnaire.html`. The respondent results are still to be collected: fill them in section 6 |

The edition matters: ISO/IEC 25010:2023 has nine characteristics (adding safety and renaming usability to interaction capability, and portability to
flexibility). This study rates five of them, the ones named in the objectives. Write "ISO/IEC 25010:2023" in the paper.
## 3. How each part of the title is met

| Title part | What the system does | Honest limits |
|---|---|---|
| **Online application** | Registration and login, eligibility pre-check, step-by-step form, document uploads with immediate file checks, draft saving, status tracking, corrections after submission, profile and password reset by SMS code | Applicants without a phone or internet still apply at the office |
| **Online renewal** | A separate renewal flow that asks only the documents showing the situation is still true (1 to 4 per category, against 4 to 8 for a new application, `database/migrate_renewal_requirements.sql`), a renewal window that opens 30 days before expiry, and the ID valid one year from handover | The renewal document list is the team's proposal and needs MSWDO confirmation. No renewal has been filed in live data yet, so show it with a prepared demo account |
| **Automated verification** | Rules, image-quality checks and OCR name matching run when the applicant reviews the draft, at submission and when staff open the record; results are saved, shown on a card and used to filter the queue | It recommends only. It cannot prove a document is genuine. OCR needs Tesseract installed on the server |
| **SMS notification** | Real texts through the IPROG gateway; every text is logged; staff see Sent or Failed at once and can retry; a Failed Texts page and menu count list what did not go through; the ID-expiry reminder is automatic | Only some texts are automatic by design (see section 4). Delivery to a phone is confirmed only by the phone |

## 4. What is automated, and what is not

| Done by the system | Done by MSWD staff |
|---|---|
| Completeness of required documents | Judging whether a document is genuine |
| Date, age and dependent-age consistency | Verifying each document (verified, needs correction, rejected) |
| File validity, size, resolution, brightness, sharpness | Interviewing and deciding eligibility |
| Duplicate files and possible duplicate applicants | Approving, rejecting and releasing the ID |
| Name from the application found in the document text (OCR) | Choosing the interview date and the ID release date |
| Ordering and filtering the queue by result | Confirming a document-correction text before it is sent |
| **Automatic SMS:** the ID-expiry reminder, 30 days before expiry, once per expiry date | **Staff-triggered SMS:** interview date, ID release schedule, document correction, cancellation and missed-interview notices |
| Reset code by SMS | Retrying a failed text |

SMS credits are limited, so "application received", "status update" and similar texts are switched off in Admin > SMS > Notification Templates.
They can be switched on at any time; the applicant always sees the status in their account.

## 5. Why this fits the title

- **Verification:** each submission is tested against defined criteria and given a result (ready, needs a closer look, needs a fix).
- **Automated:** the tests run without a person, when the applicant reviews the draft, at submission and when staff open the record.
- **Not claimed:** the system does not prove that a document is authentic or that a person is eligible. State this yourself; it is stronger
  than having the panel find it.

## 6. Evidence to bring (fill in)

| Claim | Evidence | Your result |
|---|---|---|
| The checks catch real problems | Evaluation set of [N] test applications; report from `database/auto_verification_report.php` | Recall [x]% of problem applications flagged; precision [y]% of flags justified |
| It does not slow honest applicants | "Ready" applications that staff approved without corrections | [a] of [b] ([c]%) |
| It helps staff | Average review time with and without the automated card, [n] applications | [t1] min against [t2] min |
| Users find it usable | SUS from [n] respondents | Mean [score] (benchmark 68) |
| The quality characteristics hold | ISO/IEC 25010:2023 means by characteristic (Part 3 of the questionnaire) | Functional suitability [..], Performance efficiency [..], Reliability [..], Security [..], Interaction capability [..] |
| SMS works end to end | Live send through the provider, screenshot of the SMS log and the phone | Delivered in [s] seconds on [date]. The log holds 15 real (non-test) texts accepted by the gateway at the time of writing |
| Renewal needs fewer documents | Table in section 3 and `database/test_renewal_requirements.php` | 23 renewal documents against 4 to 8 per category for a new application |
| Rules are tested | `php database/test_auto_verification.php` | 62 checks pass |
| The system is tested as a whole | Database suites on the isolated test instance, offline suites | Offline 62, 156, 31 and 15 checks pass; database suites listed in `docs/session-changes.md` |

## 7. Questions a panel is likely to ask

**Is this really "verification" if staff still decide?**
Yes. Verification here is checking a submission against rules; deciding is a separate, human act. The system removes the repetitive
checking so staff spend their time on judgment. Say: "Verification is automated; approval is not, by design, because the Solo Parent law
makes the social worker responsible for eligibility."

**What if a forged document is clean?**
The checks cannot detect that, and we say so. They catch inconsistencies, reused or unreadable files and mismatched names. Authenticity
stays with the social worker and the interview.

**How accurate is it?**
Quote your measured recall and precision and the sample size, and say the set was built by the team, so it shows the rules behave as
designed rather than their accuracy on every real-world document.

**What happens when the automatic check is wrong?**
A flag only asks staff to look; it never rejects anyone. A false alarm costs one glance. A missed problem is still caught by the staff review, because
the system never marks a document verified on its own.

**Why does renewal need fewer documents?**
The applicant already proved how they became a solo parent when they first applied, so one-time proofs (birth, marriage and death certificates,
decrees, police and medical records) are not asked again. A renewal only asks what shows the situation is still true: a new photo, a barangay
affidavit of residency and care, and the sworn sole-care affidavit, plus an updated detention or OFW proof where that situation can change. The
list is set per category in Admin > Requirements, so MSWDO can change it without a programmer.

**Why SMS, and is it reliable?**
Applicants may not check a website daily, and SMS reaches any phone. The system logs every message. When a text fails, the staff member who sent it
sees a red alert with the reason and a Retry button, the Failed Texts page lists anything that did not go through for seven days, and the
administrator has the full log. A text is retried at most three times. To save credits only the steps that need the applicant are texted. Report the
live delivery test.

**Why is the ID-expiry reminder automatic but the others are not?**
It is the one text no person triggers: nothing happens in the office on the day an ID is about to expire, so the system must act. It runs daily at
8:00 AM, texts once per expiry date, and a failure appears on the Failed Texts page.

**Does it protect personal data?**
Uploaded documents sit in a folder Apache refuses to serve directly (`storage/.htaccess`: "Require all denied"); they are streamed only by
`download.php`, and only to the owning applicant or MSWD staff. Every staff view of a document is logged, logins lock after repeated failures,
and the automated checks compare files by checksum rather than by content. Text read from documents is not saved, only the result.
The public "Check Status" page (reference number only) shows no name, only dates (no times), and calls a rejection "a decision was made"; it is
not indexed by search engines or cached. Say plainly that reference numbers are sequential, so the page still shows the limited status of any
reference; a second check (the last four digits of the mobile number) is the planned next step.

**Is the applicant's mobile number verified at sign-up?**
The code to do this is built and tested: the system texts a 6-digit code (valid 10 minutes, five wrong entries allowed, at most three codes per
sign-up) and creates the account only when it is entered. It is switched off for now (`MOBILE_VERIFY_BY_SMS` in `config/auth.php`) to save SMS credits.
Accounts the administrator creates are marked confirmed by the administrator. Password reset by SMS code is on. Switch the sign-up code on for the
demonstration and show it with a live SMS.

## 8. If you cannot show the evidence in time

Use the narrower wording: "...with Automated Document Checking and SMS Notification", keep section 4 as the definition, and present the
evaluation you have actually completed.
