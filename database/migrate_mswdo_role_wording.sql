-- Wording: the system says "MSWDO staff" and "MSWDO admin" wherever people read it.
-- Changes stored display text only: the two role names and one SMS template label. It does not touch the role keys ('admin', 'mswd_social_worker'),
-- the role codes, any id, or history (audit log, status history, notifications keep their original wording).
-- Safe to run more than once. Back up the database first.
UPDATE roles SET name = 'MSWDO Admin' WHERE role_key = 'admin' AND name = 'System Administrator';
UPDATE roles SET name = 'MSWDO Staff' WHERE role_key = 'mswd_social_worker' AND name = 'MSWD Staff / Social Worker';
UPDATE sms_templates SET label = 'Interview Date Scheduled by MSWDO Staff' WHERE template_key = 'interview_date_assigned' AND label = 'Interview Date Scheduled by Staff';
