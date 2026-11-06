-- Kalenderabonnement (issue #3): hver bruger har sin egen hemmelige nøgle i adressen til ical.php.
-- Kan køres flere gange.
ALTER TABLE users ADD COLUMN IF NOT EXISTS ical_token CHAR(64) NULL AFTER last_login_at;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_ical ON users (ical_token);
