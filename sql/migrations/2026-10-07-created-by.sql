-- Migration: hvem der satte flueben, skrev noter og tilføjede links og filer (issue #6).
-- Kræver 2026-10-07-users.sql. Kan køres flere gange (MariaDB).
ALTER TABLE event_completions
    ADD COLUMN IF NOT EXISTS completed_by INT UNSIGNED NULL AFTER completed_at,
    ADD CONSTRAINT fk_completions_user FOREIGN KEY IF NOT EXISTS (completed_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE occurrence_notes
    ADD COLUMN IF NOT EXISTS updated_by INT UNSIGNED NULL AFTER updated_at,
    ADD CONSTRAINT fk_occurrence_notes_user FOREIGN KEY IF NOT EXISTS (updated_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE occurrence_links
    ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED NULL AFTER label,
    ADD CONSTRAINT fk_occurrence_links_user FOREIGN KEY IF NOT EXISTS (created_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE occurrence_files
    ADD COLUMN IF NOT EXISTS uploaded_by INT UNSIGNED NULL AFTER uploaded_at,
    ADD CONSTRAINT fk_occurrence_files_user FOREIGN KEY IF NOT EXISTS (uploaded_by) REFERENCES users(id) ON DELETE SET NULL;
