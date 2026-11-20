-- Removes four tables whose features were retired and that no code reads or writes any more:
--   qr_application_slips  (QR slips)           system_broadcasts   (admin announcement broadcasts)
--   chat_messages         (Chat Support)       public_announcements (public news)
-- Also removes the SMS template "interview_booking_open" (staff can no longer open interview booking by hand).
-- None of these has a foreign key pointing at it. Irreversible: take a database backup first. Safe to run more than once.
DROP TABLE IF EXISTS qr_application_slips;
DROP TABLE IF EXISTS system_broadcasts;
DROP TABLE IF EXISTS chat_messages;
DROP TABLE IF EXISTS public_announcements;
DELETE FROM sms_templates WHERE template_key = 'interview_booking_open';
