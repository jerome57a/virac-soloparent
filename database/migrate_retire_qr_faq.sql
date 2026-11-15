-- Update the obsolete applicant help answer after QR functionality removal.
-- No application, user, document, or QR slip records are changed.
UPDATE faqs
SET question = 'Where can I find my application reference?',
    answer = 'Log in and open My Application to see your reference number and current status. Bring the reference number when you contact or visit MSWDO.'
WHERE question = 'I lost my application slip. What do I do?';
