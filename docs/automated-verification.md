# Automated verification

The system checks every submitted application and its uploaded files against fixed rules, records a result with plain-words
reasons, and uses that result to order and filter the staff queue. It **recommends**: the MSWD social worker still verifies each
document and decides the application.

## What it checks

| Check | Result when it fails | Where |
|---|---|---|
| Every required document is uploaded (or carried over verified, for a renewal) | Fail | all applications |
| Applicant birthdate is a real date, not in the future | Fail | all |
| Applicant age is 18-100 | Flag | all |
| Each child's birthdate is real and not in the future | Fail | new applications |
| A new application lists at least one child | Flag | new |
| A child was not born before, or when the parent was under 12 | Flag | new |
| A dependent is 21 or younger (an older one needs a disability) | Flag | new |
| Same name and birthdate on another account | Flag (staff only) | all |
| Mobile number is a valid Philippine number; emergency contact present | Flag | all / new |
| Eligibility assessment does not say Not Eligible | Flag | all |
| File is not used for two documents, nor in another applicant's application | Flag | each file |
| Photo resolution (shortest side 600 px), brightness, sharpness | Flag | JPG/PNG files |
| A name from the application appears in the document's text (OCR) | Flag | JPG/PNG, when Tesseract is installed |

Verdict: **ready** (nothing flagged or failed), **review** (at least one flag) or **fix** (at least one failure).

## Where it appears

- **Applicant, review step:** the same checks on the draft, before submitting. Advisory only; it never blocks. Nothing about other
  applicants is shown. OCR is not run here (it is slow).
- **Staff, application page:** the "Automated verification" card with reasons.
- **Staff, applications list:** an "Auto-check" pill on each card and an "Auto-check" filter.
- **Saved results:** `application_auto_checks` (run `database/migrate_auto_verification.sql`). A row is saved at submission and
  whenever staff open an application whose result changed.

## OCR name matching (optional)

Needs the Tesseract program on the server. Without it the check is skipped silently and the staff card says so.

1. Install Tesseract for Windows (the UB Mannheim build) with the English language data.
2. Either keep the default folder (`C:\Program Files\Tesseract-OCR\`) or set the `TESSERACT_PATH` environment variable to
   `tesseract.exe` for the PHP process.
3. Submit a test application. The text is read once, at submission; only the match result is saved, never the text.

Names match by word (accents and case ignored, one misread letter tolerated in words of five letters or more). A name counts as found when
at least half of its words appear. Any document may carry the applicant's name or a child's name.

## Tuning the photo limits

The limits at the top of `services/AutoVerificationService.php` (`MIN_IMAGE_SIDE`, `MIN_SHARPNESS`, `MIN_BRIGHTNESS`,
`MAX_BRIGHTNESS`) are starting values. Collect sample photos (made-up or consenting documents, outside the project folder), name
them `good_...`, `blurry_...`, `dark_...`, `bright_...`, `small_...` and run:

    php database/auto_verification_samples.php <folder>

## Evaluating it

    php database/auto_verification_report.php

Compares the system's first recommendation for each application with what staff did (approved, corrections requested, rejected) and prints
how many "ready" applications went through cleanly, how many problem applications the system had flagged, and how many flagged ones really
needed corrections. Report these numbers, with the sample size, in the evaluation chapter.

## Tests

    php database/test_auto_verification.php

Runs the rules, image checks, draft mapping and OCR name matching on plain data and generated images. No database, no network.

## Limits to state honestly

- It does not decide authenticity. It cannot tell a forged but clean document from a genuine one.
- OCR accuracy depends on photo quality and on Tesseract; it only raises flags, never rejects.
- Image limits are starting values until tuned on real photos.
- The eligibility rules follow RA 8972 and RA 11861 as the office has configured them; staff make the final determination.
