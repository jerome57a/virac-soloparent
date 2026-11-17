# SMS wording: full (deployment) and compact (small test token)

The gateway charges per 160-character text, and it adds its own header (for example `EsoloparentID`) to every message. A longer
message costs more credits. While testing with a small token the system can use shorter wording; before deployment it goes
back to the full wording.

| Setting | Wording |
|---|---|
| `'SMS_COMPACT_TEXTS' => false` (the default) | **Full wording**: the approved texts. Use this for deployment. |
| `'SMS_COMPACT_TEXTS' => true` (in `config/local.php`) | **Compact wording**: each common text fits one 160-character SMS with the gateway header. |

## Going back to the full wording before deployment

1. In `config/local.php` set `'SMS_COMPACT_TEXTS' => false`.
2. Run `php database/sms_wording.php full` from the project folder. It switches the stored templates back (only rows that still
   hold the compact text word for word; a template an administrator edited by hand is never overwritten).
3. Open Admin > SMS Management and check the texts. Open a staff review page and check the previews (correction SMS, schedule SMS,
   ID release SMS).

`php database/sms_wording.php compact` does the reverse.

## What the setting changes

| Message | Compact | Full |
|---|---|---|
| Application received, renewal received / reminder / expired, appointment reminder, interview booked | shortened templates (`SMSService::COMPACT_TEMPLATES`) | `SMSService::TEMPLATES` |
| Interview scheduled by staff | "Interview for {ref} is on Mon, Nov 23, 2026, 7AM-5PM. Come then, or log in to e-SoloParentID to pick another date." | the long date, "7:00 AM to 5:00 PM", the office address and "log in to your e-SoloParentID account" |
| Document needs correction | "Your "{document}" needs correction. Log in, open Documents and upload a new file." (a count replaces the names when they do not fit) | `SMSService::CORRECTION_BODY` (the approved wording) |
| ID release scheduled (automatic text) | "ID release on {when} at {location}. Bring a valid ID." | `SMSService::idReleaseTemplate()` full text |

Templates that are not in the table (status change, password reset, appointment cancelled or missed) are not affected.
Staff-written custom messages are never changed.

## Other credit savers (config/local.php)

`SMS_ALLOWED_NUMBERS` (only these numbers get real texts), `SMS_DAILY_LIMIT` (most real texts per day). Clear the allowlist and
raise the limit when the system goes live. An identical text to the same number within 10 minutes is always skipped.
