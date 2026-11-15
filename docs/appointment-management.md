# Appointment management

Staff assign the first interview and any replacement interview by typing a date and time. There is no slot setup, capacity limit or applicant self-booking. Applicants may cancel a booked interview before the configured notice deadline, or contact MSWDO.

1. Open **Appointment Management → Needs a schedule** and choose **Assign schedule** for an applicant.
2. In the application's **Interview** section, enter the **date** and **time** and the location. Review the automatic SMS or write a custom message, then save with **Schedule Interview and Send SMS**. The appointment becomes **BOOKED** and is saved with no slot (`appointments.slot_id` is NULL). SMS test mode logs the message without delivering it; provider failures stay visible for retry.
3. The date and time are validated on the server: a real calendar date, in the future, on an office day (not a weekend, holiday or office closure), and within office hours. Closed days and invalid times are rejected.
4. The appointment appears in **Today's appointments** or **Upcoming appointments**. To move it, save a new date/time from the same application. This resets its reminder. Repeating an identical save does not send a duplicate SMS.
5. Once the scheduled time arrives, use **Record result** and choose **Successful** or **Unsuccessful**. The appointment becomes **COMPLETED**. This does not approve the application; document and eligibility checks remain separate.
6. For a cancellation, provide a reason. For a no-show, choose **Mark missed** after the scheduled time. The application returns to its previous processing status and reappears in **Needs a schedule**.

**Historical data:** The `appointment_slots` table and older appointments that still carry a `slot_id` are kept for history. Rescheduling such an appointment clears its `slot_id`.

**Roles:** MSWD staff assign schedules and record results. Administrators keep their monitoring access.

Validation uses synthetic applicants in a disposable database with SMS forced to test mode (see `docs/runtime-test-plan.md`).
