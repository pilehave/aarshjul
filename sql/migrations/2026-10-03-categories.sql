-- Migration: farver på begivenheder erstattes af kategorier (navn + farve).
-- Kør på en eksisterende database:  mysql -u root -p aarshjul < sql/migrations/2026-10-03-categories.sql
-- Hver forskellig farve bliver til en kategori ("Kategori 1", "Kategori 2" ...), som kan omdøbes bagefter.
SET NAMES utf8mb4;

CREATE TABLE categories (
    id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(100) NOT NULL,
    color  CHAR(7) NOT NULL,
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

INSERT INTO categories (name, color)
SELECT CONCAT('Kategori ', ROW_NUMBER() OVER (ORDER BY MIN(id))), LOWER(color)
FROM events GROUP BY LOWER(color);

ALTER TABLE events ADD COLUMN category_id INT UNSIGNED NULL AFTER description;
UPDATE events e JOIN categories c ON c.color = LOWER(e.color) SET e.category_id = c.id;
ALTER TABLE events
    MODIFY category_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_events_category FOREIGN KEY (category_id) REFERENCES categories(id),
    DROP COLUMN color;
