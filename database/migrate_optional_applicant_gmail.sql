-- Self-registered Solo Parents use mobile / optional Gmail instead of a username.
-- Existing accounts, passwords and usernames are preserved. Run against the configured database.
ALTER TABLE users MODIFY COLUMN username VARCHAR(50) DEFAULT NULL;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_email (email);
