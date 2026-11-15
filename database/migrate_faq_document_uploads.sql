-- PROPOSED, NOT EXECUTED. Phase 1 data change for EXISTING databases only
-- (a fresh install already gets the new text from seed.sql).
--
-- Replaces the obsolete FAQ about booking appointments online with one that describes
-- uploading supporting documents. The row is matched by its exact original question AND
-- answer text, so an FAQ that staff already reworded is left alone, and no row is deleted.
-- Review and run only against a verified backup, with the target database selected by the operator.
UPDATE faqs
SET question = 'How do I upload my supporting documents?',
    answer = 'Open Documents in your dashboard. For each required document, choose a clear scan or photo (JPG, PNG or PDF, up to the maximum size shown on the page) and select Upload document. MSWDO staff review each file and may ask you to replace it; replace it from the same page. Uploads close once your application is approved or rejected.'
WHERE question = 'How do I book, reschedule or cancel an appointment?'
  AND answer = 'Open Appointment in your dashboard and pick an open slot. You can reschedule or cancel online until a few hours before your appointment (the exact cut-off is shown on the page); after that, please contact the MSWDO office.';
