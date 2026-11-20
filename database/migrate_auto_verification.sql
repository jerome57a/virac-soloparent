-- Saved results of the automated verification (services/AutoVerificationService.php): the system's recommendation
-- for an application at a point in time. Append-only history: a row is written when the application is submitted and
-- whenever staff open it and the result has changed. Safe to run more than once. Nothing else is altered.
CREATE TABLE IF NOT EXISTS application_auto_checks (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  verdict        ENUM('ready','review','fix') NOT NULL,
  pass_count     SMALLINT NOT NULL DEFAULT 0,
  flag_count     SMALLINT NOT NULL DEFAULT 0,
  fail_count     SMALLINT NOT NULL DEFAULT 0,
  checks         JSON NOT NULL,                      -- every check: key, scope, result, label, message
  trigger_event  VARCHAR(20) NOT NULL DEFAULT 'submitted',  -- submitted | review
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_autocheck_app (application_id, id),
  CONSTRAINT fk_autocheck_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
