-- Migration: note, links og filer på den enkelte forekomst (issue #12).
-- Kør på en eksisterende database:  mysql -u root -p aarshjul < sql/migrations/2026-10-05-occurrence-notes.sql
-- Note, links og filer, der kun gælder én forekomst. Nøglen er den samme som i event_completions,
-- så næste forekomst af samme begivenhed starter uden note.
CREATE TABLE IF NOT EXISTS occurrence_notes (
    event_id         INT UNSIGNED NOT NULL,
    occurrence_date  DATE NOT NULL,
    note             TEXT NOT NULL,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, occurrence_date),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS occurrence_links (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id         INT UNSIGNED NOT NULL,
    occurrence_date  DATE NOT NULL,
    url              VARCHAR(2000) NOT NULL,
    label            VARCHAR(200) NULL,
    KEY idx_occurrence_links (event_id, occurrence_date),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS occurrence_files (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id         INT UNSIGNED NOT NULL,
    occurrence_date  DATE NOT NULL,
    original_name    VARCHAR(255) NOT NULL,
    stored_name      VARCHAR(100) NOT NULL,   -- tilfældigt filnavn i storage/uploads
    mime_type        VARCHAR(150) NOT NULL,
    size_bytes       INT UNSIGNED NOT NULL,
    uploaded_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_occurrence_files (event_id, occurrence_date),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;
