-- Migration: glemt adgangskode og invitationer (issue #6).
-- Kør på en eksisterende database, se README.
-- Engangslinks til at vælge adgangskode: "Glemt adgangskode" (reset) og invitation af nye brugere (invite).
-- Kun sha256 af nøglen gemmes, så en kopi af databasen ikke kan bruges til at overtage brugere.
CREATE TABLE IF NOT EXISTS password_resets (
    token_hash  CHAR(64) NOT NULL PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    purpose     ENUM('reset','invite') NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    KEY idx_password_resets_user (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
