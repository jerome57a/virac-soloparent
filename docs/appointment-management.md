# Appointment management

Staff assign the first interview and replacement interviews. Applicants may reschedule an existing booked interview before the configured notice deadline, or contact MSWDO for help.

1. Open **Appointment Management → Manage Slots**. Add an office date, start/end time and capacity. Closed days, invalid times and overlapping slots are rejected.
2. Return to **Appointment Management → Needs a schedule**. Open an applicant using **Assign schedule**.
3. In the application's **Interview** section, choose an available **Interview slot**, enter the location, and review the automatic SMS or write a custom message. Save using **Schedule Interview and Send SMS**. The appointment becomes **BOOKED** and reserves one place. SMS test mode logs the message without delivering it; provider failures remain visible for retry.
4. The appointment appears in **Today's appointments** or **Upcoming appointments**. To move it, select a different slot from the same application. Saving releases the old seat and reserves the new one. Repeating an identical save does not send a duplicate SMS.
5. Once the scheduled time arrives, use **Record result** and choose **Successful** or **Unsuccessful**. The appointment becomes **COMPLETED**. This does not approve the application; document and eligibility checks remain separate.
6. For a cancellation, provide a reason. For a no-show, choose **Mark missed** after the scheduled time. The seat is freed and the application returns to its previous processing status. The applicant is told to contact MSWDO for a new schedule, and the application reappears in **Needs a schedule**.

**Full or closed slots:** They cannot receive another booking. Creating a slot does not assign an applicant automatically. Cancelling an entire slot cancels its booked appointments and notifies the affected applicants. Slots with appointment history cannot be deleted.

**Existing appointments:** Earlier staff appointments without slot links remain intact and count against a managed slot covering their scheduled time. Staff choose a managed slot when changing their schedule.

**Roles:** MSWD staff assign schedules and record results. Administrators retain their existing monitoring and slot-management access. Sidebar navigation and unrelated workflows are unchanged.

Validation uses synthetic applicants in a disposable database with SMS forced to test mode. The form preview uses synthetic slots and never submits to the live application.
