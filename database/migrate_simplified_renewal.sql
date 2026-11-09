-- XAMPP/MariaDB additive migration. No existing requirements, reviews or renewals are rewritten.
-- Alternatively run: php database/sync_schema.php --apply
ALTER TABLE applications ADD COLUMN IF NOT EXISTS renewal_simplified TINYINT(1) NOT NULL DEFAULT 0 AFTER id_expires_at;
ALTER TABLE application_document_checklists
  ADD COLUMN IF NOT EXISTS expires_at DATE DEFAULT NULL AFTER reviewed_at,
  ADD COLUMN IF NOT EXISTS source_checklist_id INT DEFAULT NULL AFTER expires_at,
  ADD COLUMN IF NOT EXISTS requirement_label VARCHAR(255) DEFAULT NULL AFTER source_checklist_id,
  ADD COLUMN IF NOT EXISTS required_snapshot TINYINT(1) DEFAULT NULL AFTER requirement_label;
CREATE TABLE IF NOT EXISTS renewal_requirements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  doc_key VARCHAR(50) NOT NULL,
  label VARCHAR(255) NOT NULL,
  always_refresh TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_renewal_requirement (category_id,doc_key),
  CONSTRAINT fk_renewal_requirement_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;
