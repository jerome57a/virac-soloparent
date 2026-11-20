-- Wording: the system says "Solo Parent" instead of "applicant" wherever people read it.
-- Changes stored text only: the role name, the category descriptions, one document label and one Help answer. It does not touch the role key
-- 'applicant' (the code and the users table depend on it), any id, or history (audit log, status history, notifications keep their original wording).
-- Safe to run more than once. Back up the database first.
UPDATE roles SET name = 'Solo Parent' WHERE role_key = 'applicant' AND name = 'Solo Parent Applicant';

UPDATE categories
SET description = REPLACE(REPLACE(REPLACE(description, 'applicants', 'Solo Parents'), 'applicant', 'Solo Parent'), 'Applicant', 'Solo Parent')
WHERE description LIKE '%applicant%';

UPDATE document_requirements
SET label = REPLACE(REPLACE(REPLACE(label, 'applicants', 'Solo Parents'), 'applicant', 'Solo Parent'), 'Applicant', 'Solo Parent')
WHERE label LIKE '%applicant%';

UPDATE faqs
SET answer = REPLACE(REPLACE(REPLACE(answer, 'applicants', 'Solo Parents'), 'applicant', 'Solo Parent'), 'Applicant', 'Solo Parent')
WHERE answer LIKE '%applicant%';
