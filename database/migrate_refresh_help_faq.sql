-- Replace the obsolete assistant-related FAQ with current eligibility guidance.
-- This preserves the FAQ row and any answers that staff have already customized.
UPDATE faqs
SET question = 'Does the Eligibility Checker approve my application?',
    answer = 'No. It gives a preliminary result only. MSWDO reviews your application and supporting documents before making a final decision.'
WHERE question = 'Can the online assistant tell me if I am eligible or approved?'
  AND answer LIKE '%assistant can explain the process%';
