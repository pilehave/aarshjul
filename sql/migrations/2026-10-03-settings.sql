-- Migration: tabel til indstillinger (fx hvilken måned årshjulet starter med).
-- Kør på en eksisterende database:  mysql -u root -p aarshjul < sql/migrations/2026-10-03-settings.sql
CREATE TABLE IF NOT EXISTS settings (
    name   VARCHAR(50) NOT NULL PRIMARY KEY,
    value  VARCHAR(255) NOT NULL
) ENGINE=InnoDB;
