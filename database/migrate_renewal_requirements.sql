-- Documents an applicant presents when RENEWING a Solo Parent ID, per active category (10 to 18).
-- A renewal asks fewer documents than a new application: one-time proofs (birth, marriage and death certificates, decrees,
-- CENOMAR, police and medical records) are not repeated. Only documents that show the situation is still true today are asked,
-- and each is uploaded fresh at every renewal (always_refresh = 1).
--   10, 12, 13, 14, 16, 18 : barangay affidavit of residency and parental care, sworn sole-care affidavit
--                            (the two 1x1 ID pictures are brought to the interview, not uploaded: never a renewal document,
--                            see migrate_data_alignment.php)
--   11, 15, 17             : sworn sole-care affidavit
--   14 (extra)             : updated certificate of detention
--   18 (extra)             : updated proof the OFW is still working abroad
-- Labels of the shared documents are copied from the new-application list so the wording matches. Safe to run more than once
-- (an existing row is updated, never duplicated). Edit the list later in Admin > Requirements. Back up the database first.
INSERT INTO renewal_requirements (category_id, doc_key, label, always_refresh, sort_order)
SELECT d.category_id, d.doc_key, d.label, 1, p.sort_order
FROM document_requirements d
JOIN (
  SELECT 10 AS category_id, 'barangayCareAffidavit' AS doc_key, 2 AS sort_order UNION ALL
  SELECT 10, 'soleCareAffidavit', 3 UNION ALL
  SELECT 11, 'soleCareAffidavit', 1 UNION ALL
  SELECT 12, 'barangayCareAffidavit', 2 UNION ALL
  SELECT 12, 'soleCareAffidavit', 3 UNION ALL
  SELECT 13, 'barangayCareAffidavit', 2 UNION ALL
  SELECT 13, 'soleCareAffidavit', 3 UNION ALL
  SELECT 14, 'barangayCareAffidavit', 2 UNION ALL
  SELECT 14, 'soleCareAffidavit', 3 UNION ALL
  SELECT 15, 'soleCareAffidavit', 1 UNION ALL
  SELECT 16, 'barangayCareAffidavit', 2 UNION ALL
  SELECT 16, 'soleCareAffidavit', 3 UNION ALL
  SELECT 17, 'soleCareAffidavit', 1 UNION ALL
  SELECT 18, 'barangayCareAffidavit', 2 UNION ALL
  SELECT 18, 'soleCareAffidavit', 3
) p ON p.category_id = d.category_id AND p.doc_key = d.doc_key
JOIN categories c ON c.id = d.category_id
ON DUPLICATE KEY UPDATE label = VALUES(label), always_refresh = 1, sort_order = VALUES(sort_order);

-- The two "updated proof" documents have their own renewal wording.
INSERT INTO renewal_requirements (category_id, doc_key, label, always_refresh, sort_order)
SELECT c.id, v.doc_key, v.label, 1, 4
FROM categories c
JOIN (
  SELECT 14 AS category_id, 'detentionCertificate' AS doc_key, 'Updated certificate of detention, or certification that the spouse is still serving the sentence' AS label UNION ALL
  SELECT 18, 'ofwPassportProof', 'Updated proof the OFW is still working abroad (passport copy or Bureau of Immigration record)'
) v ON v.category_id = c.id
ON DUPLICATE KEY UPDATE label = VALUES(label), always_refresh = 1, sort_order = 4;
